<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\ConversationReadModel;
use App\Models\WhatsApp\MessageModel;
use App\Models\CRM\OrderModel;
use App\Services\QurbaniClassifier;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class WhatsAppWebController extends Controller
{
    /**
     * Resolve the current user's WhatsApp-view access:
     *   - 'allowed'  : true when either full or limited view permission is granted
     *   - 'limited'  : true when ONLY the limited permission is granted
     *                  (full permission overrides limited)
     *   - 'cutoff'   : Carbon timestamp; inbound data older than this must be
     *                  hidden from limited users. Start of yesterday.
     *
     * The "limited" flavour restricts the user to conversations / messages
     * from today and day-1 only. This is enforced in every read endpoint
     * below so the restriction cannot be bypassed by crafting requests.
     */
    protected function resolveWhatsAppAccess(): array
    {
        $user = auth()->user();
        $hasFull = $user && $user->hasMobilePermission('view_whatsapp_messages');
        $hasLimited = $user && $user->hasMobilePermission('view_whatsapp_messages_limited');

        return [
            'allowed' => $hasFull || $hasLimited,
            'limited' => !$hasFull && $hasLimited,
            // Start of yesterday (local app timezone): this is the earliest
            // message/conversation timestamp limited users are allowed to see.
            'cutoff'  => now()->subDay()->startOfDay(),
        ];
    }

    /**
     * Apr-2026: Single source of truth for "which conversations are
     * currently unread for this user". Used by:
     *   1. getConversations() when filter=unread, so the Unread tab can
     *      span the whole DB instead of being a client-side filter on
     *      the loaded 200.
     *   2. markAllRead() to know which conversations to flip.
     *
     * Mirrors the badge query in getUnreadCount() byte-for-byte:
     *   - inbound only
     *   - newer than this user's last_read_at (or no read row)
     *   - newer than global_read_at (super-reader marker, when present)
     *   - no outbound staff reply AFTER this inbound
     *   - if user is "limited", restrict to their visible window
     * Plus we union in any conversation explicitly Mark-Unread-ed by this
     * user via t_wa_conversation_reads.forced_unread_at, since those are
     * conversations the operator INTENTIONALLY left in the unread bucket.
     *
     * Returns an array<int> of conversation ids. Empty when the user has
     * no unread (or no schema support).
     */
    protected function unreadConversationIdsForCurrentUser(array $access): array
    {
        $userId = auth()->id();
        if (!$userId || !Schema::hasTable('t_wa_conversation_reads')) {
            return [];
        }

        $q = DB::table('t_wa_messages as m')
            ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                $j->on('r.conversation_id', '=', 'm.conversation_id')
                  ->where('r.user_id', '=', $userId);
            })
            ->where('m.direction', 'inbound')
                ->where('m.created_at', '>=', \App\Models\FIN\ConfigModel::get('wa_unread_floor_date', '2026-06-28'))
            ->where(function ($w) {
                $w->whereNull('r.last_read_at')
                  ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
            })
            ->whereRaw('NOT EXISTS (
                SELECT 1 FROM t_wa_messages m2
                WHERE m2.conversation_id = m.conversation_id
                  AND m2.direction = \'outbound\' AND m2.sent_by IS NOT NULL
                  AND m2.created_at > m.created_at
            )');

        // Inbound taps the system already answered (e.g. "Get bank details")
        // stay in the chat history but never raise a badge. See UnreadQuery.
        \App\Services\WhatsApp\UnreadQuery::exclude($q);

        if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $q->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
              ->where(function ($w) {
                  $w->whereNull('c.global_read_at')
                    ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
              });
        }

        if ($access['limited']) {
            $q->where('m.created_at', '>=', $access['cutoff']);
        }

        $organicIds = $q->distinct()->pluck('m.conversation_id')->all();

        // Forced-unread bucket — Mark-as-Unread-ed by this user. We add
        // these on top of the organic set; the inbox's job is to surface
        // every conversation the operator considers "still pending".
        $forcedIds = [];
        if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
            $forcedIds = DB::table('t_wa_conversation_reads')
                ->where('user_id', $userId)
                ->whereNotNull('forced_unread_at')
                ->pluck('conversation_id')
                ->all();
        }

        // array_values + array_unique so callers get a clean integer list.
        $merged = array_values(array_unique(array_merge(
            array_map('intval', $organicIds),
            array_map('intval', $forcedIds)
        )));
        return $merged;
    }

    public function index()
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            abort(403, 'You do not have permission to view WhatsApp Messages.');
        }
        $user = auth()->user();
        $waCanManageAutoReply = $user
            && method_exists($user, 'hasMobilePermission')
            && $user->hasMobilePermission('manage_wa_auto_reply');
        // Apr-2026: super-reader flag drives the Mark-All-Read button on
        // the Unread tab. Only Taimur's role gets `whatsapp_super_reader`,
        // and only super-readers can mark globally — for everyone else the
        // bulk button is hidden and they continue clearing one by one.
        $waIsSuperReader = app(WhatsAppService::class)->isSuperReader($user);
        return view('pages.messages.index', [
            'waIsLimited' => $access['limited'],
            'waCutoffAt'  => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
            'waCanManageAutoReply' => $waCanManageAutoReply,
            'waIsSuperReader' => $waIsSuperReader,
        ]);
    }

    public function getConversations(Request $request)
    {
        if (!Schema::hasTable('t_wa_conversations')) {
            return response()->json(['success' => true, 'conversations' => []]);
        }

        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $userId = auth()->id();
        $hasReadsTable = Schema::hasTable('t_wa_conversation_reads');
        $hasQurbaniCol = Schema::hasColumn('t_wa_conversations', 'is_qurbani');

        $query = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city,latitude,longitude,verified_location_url')
            ->orderByDesc('last_message_at');

        // Limited view: only conversations whose most recent activity is within
        // the last two days (today + yesterday). Older conversations are
        // invisible and their messages cannot be fetched (see getMessages).
        if ($access['limited']) {
            $query->where('last_message_at', '>=', $access['cutoff']);
        }

        // Qurbani tab shows only conversations auto-flagged as qurbani.
        if ($request->filter === 'qurbani' && $hasQurbaniCol) {
            $query->where('is_qurbani', 1);
        }

        // Unread tab — Apr-2026: prefilter to a DB-wide list of this user's
        // unread conversation ids instead of relying on the post-fetch filter
        // (line ~482) inside the top-200 window. Users with hundreds of stale
        // unread one-message threads were seeing a tiny subset of their actual
        // unread list because the older threads sat below the cursor. Now we
        // compute the full unread set up front and let the rest of the query
        // (sort, limit, search) operate on it. The post-fetch filter is still
        // there as a belt-and-braces guard.
        $unreadOnly = ($request->filter === 'unread');
        if ($unreadOnly) {
            $unreadIds = $this->unreadConversationIdsForCurrentUser($access);
            if (empty($unreadIds)) {
                return response()->json([
                    'success' => true,
                    'conversations' => [],
                    'is_limited' => $access['limited'],
                    'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
                    'has_more' => false,
                    'next_cursor' => null,
                    'total_unread' => 0,
                ]);
            }
            $query->whereIn('id', $unreadIds);
        }

        // Two search modes, driven by the UI toggle next to the input:
        //   - 'customers' (default, legacy behaviour): matches name / phone /
        //     WA contact name on the conversation + linked customer row.
        //   - 'chats':    full-text-ish LIKE over t_wa_messages.content, so the
        //                 user can find a conversation by something that was
        //                 actually said ("cuts", "refund", etc.). We pre-fetch
        //                 matching message rows so we can surface a highlighted
        //                 snippet back to the UI and only return conversations
        //                 that actually matched.
        //
        // For chat-mode we also honour the limited-access cutoff so a limited
        // user can never peek at old messages via a content search.
        $searchMode = $request->input('search_mode', 'customers');
        $matchByConvId = []; // conversation_id => [snippet, match_count]
        if ($search = $request->search) {
            if ($searchMode === 'chats') {
                $msgQuery = DB::table('t_wa_messages')
                    ->select('conversation_id', 'content', 'created_at', 'direction')
                    ->where('content', 'like', "%{$search}%")
                    ->orderByDesc('created_at');
                if ($access['limited']) {
                    $msgQuery->where('created_at', '>=', $access['cutoff']);
                }
                // Cap the scan so a pathological query (e.g. "a") can't
                // blow up memory. 600 rows is plenty to populate the 150
                // conversation list on the left with previews.
                $matches = $msgQuery->limit(600)->get();

                foreach ($matches as $m) {
                    if (!isset($matchByConvId[$m->conversation_id])) {
                        $matchByConvId[$m->conversation_id] = [
                            'snippet' => $this->buildChatSnippet((string) $m->content, (string) $search),
                            'snippet_at' => $m->created_at,
                            'snippet_direction' => $m->direction,
                            'count' => 1,
                        ];
                    } else {
                        $matchByConvId[$m->conversation_id]['count']++;
                    }
                }
                $matchedConvIds = array_keys($matchByConvId);
                if (empty($matchedConvIds)) {
                    // Short-circuit: no content match → empty list. Skip
                    // the expensive downstream queries entirely.
                    return response()->json([
                        'success' => true,
                        'conversations' => [],
                        'is_limited' => $access['limited'],
                        'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
                        'search_mode' => 'chats',
                    ]);
                }
                $query->whereIn('id', $matchedConvIds);
            } else {
                $query->where(function ($q) use ($search) {
                    $q->where('wa_phone', 'like', "%{$search}%")
                      ->orWhere('wa_contact_name', 'like', "%{$search}%")
                      ->orWhereHas('customer', function ($cq) use ($search) {
                          $cq->where('first_name', 'like', "%{$search}%")
                             ->orWhere('last_name', 'like', "%{$search}%")
                             ->orWhere('phone_normalized', 'like', "%{$search}%")
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                      });
                });
            }
        }

        // Apr-2026: switched from a hard 150-cap to cursor pagination.
        //   - First page (no cursor) returns up to 200 most-recent conversations.
        //   - Subsequent pages pass `before_last_message_at` (ISO8601) as the
        //     cursor and request up to 100 older rows. Cursor uses
        //     last_message_at because the list is sorted on it; page numbers
        //     would shift any time a tail conversation got new activity.
        //   - Hard ceiling of 300 on a single request to keep payload + the
        //     downstream COUNT(*) joins predictable. Frontend can chain
        //     pages by using the oldest visible last_message_at as the next
        //     cursor.
        $isPagedFetch = (bool) $request->input('before_last_message_at');
        $perPage = (int) ($request->input('limit') ?: ($isPagedFetch ? 100 : 200));
        $perPage = max(1, min($perPage, 300));

        // For the Unread tab we already restricted the candidate set to the
        // user's actual unread ids — return the whole list in one go (capped
        // at 500 to be safe) and skip cursor pagination. The list is
        // self-bounded in practice and Mark-as-Read is per-row, so the
        // operator needs to see all of them at once.
        if ($unreadOnly) {
            $perPage = min(count($unreadIds), 500);
            $isPagedFetch = false;
        }

        if ($isPagedFetch) {
            try {
                $cursor = \Carbon\Carbon::parse($request->input('before_last_message_at'));
                // <= rather than strict <. The boundary conversation can
                // slide out of the top 200 between the moment the cursor
                // was captured and the moment the operator clicks Load
                // More — strict < would leave it in a gap (not in the
                // live head, not in extras). We tolerate one duplicate
                // row at the boundary because the frontend dedupes by id
                // before rendering, so users never see it twice.
                $query->where('last_message_at', '<=', $cursor);
            } catch (\Exception $e) {
                // Bad cursor → treat as first-page fetch.
                $isPagedFetch = false;
            }
        }

        // Fetch conversations + last-read timestamps for this user in one shot.
        $lastReadMap = [];
        $convs = $query->limit($perPage)->get();

        // Pull in chats the viewer was tagged on that fall OUTSIDE this page —
        // the list is sorted by last_message_at, so a fresh tag on an old
        // thread would otherwise never reach the pinned section. On the "@ me"
        // filter, ignore the freshness window so that filter is genuinely
        // complete (it used to search only the loaded page). Mirrors the API
        // controller; see WhatsAppController::MENTION_PIN_HOURS.
        if (!$isPagedFetch
            && Schema::hasTable('t_wa_conversation_labels')
            && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
            $wantsAllMentions = $request->boolean('assigned_to_me');
            $mentionIds = DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                ->where('l.user_id', $userId)
                ->whereNull('cl.mention_seen_at')
                ->when(!$wantsAllMentions, fn($q) => $q->where(
                    'cl.applied_at', '>=',
                    now()->subHours(\App\Http\Controllers\API\WhatsAppController::MENTION_PIN_HOURS)
                ))
                ->distinct()->pluck('cl.conversation_id')->map(fn($i) => (int) $i)->all();

            $missing = array_diff($mentionIds, $convs->pluck('id')->map(fn($i) => (int) $i)->all());
            if (!empty($missing)) {
                $extra = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city,latitude,longitude,verified_location_url')
                    ->whereIn('id', $missing)
                    // A limited-access user must not gain reach through a tag.
                    ->when($access['limited'], fn($q) => $q->where('last_message_at', '>=', $access['cutoff']))
                    ->get();
                $convs = $convs->concat($extra);
            }
        }

        $convIds = $convs->pluck('id')->all();

        // Backfill missing customer_id links for conversations whose customer
        // record was created AFTER the conversation existed. We do this in
        // batch to keep it fast: lookup all unmerged customers whose phone
        // matches any of the unlinked conversations in this page, then update
        // each match. Subsequent loads of those convs already show the
        // customer (with orders panel etc.) without any extra round-trip.
        $unlinked = $convs->filter(fn($c) => empty($c->customer_id) && !empty($c->wa_phone));
        if ($unlinked->isNotEmpty()) {
            $phoneSuffixMap = []; // last-10-digit suffix => conv
            foreach ($unlinked as $c) {
                $suffix = substr(ltrim($c->wa_phone, '+'), -10);
                if ($suffix !== '') {
                    $phoneSuffixMap[$suffix] = $c;
                }
            }
            if (!empty($phoneSuffixMap)) {
                try {
                    $matchedCustomers = \App\Models\CRM\CustomerModel::whereIn('phone_normalized', array_keys($phoneSuffixMap))
                        ->whereNull('merged_into_customer_id')
                        ->orderByDesc('updated_at')
                        ->get(['id', 'first_name', 'last_name', 'phone_normalized', 'city']);

                    // Group by phone (most recently updated wins, since we ordered desc).
                    $customerByPhone = [];
                    foreach ($matchedCustomers as $cust) {
                        if (!isset($customerByPhone[$cust->phone_normalized])) {
                            $customerByPhone[$cust->phone_normalized] = $cust;
                        }
                    }
                    foreach ($phoneSuffixMap as $suffix => $conv) {
                        if (isset($customerByPhone[$suffix])) {
                            $cust = $customerByPhone[$suffix];
                            $conv->customer_id = $cust->id;
                            $conv->setRelation('customer', $cust);
                            ConversationModel::where('id', $conv->id)
                                ->whereNull('customer_id')
                                ->update(['customer_id' => $cust->id]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('getConversations: customer auto-relink batch failed', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($hasReadsTable && $userId && !empty($convIds)) {
            $lastReadMap = ConversationReadModel::where('user_id', $userId)
                ->whereIn('conversation_id', $convIds)
                ->pluck('last_read_at', 'conversation_id')
                ->map(fn($v) => $v ? \Carbon\Carbon::parse($v) : null)
                ->all();
        }

        // Unread-for-this-user count = inbound messages created after my last_read_at.
        // Batched with one grouped query to avoid N+1.
        $unreadByConv = [];
        if (!empty($convIds)) {
            $msgQ = DB::table('t_wa_messages')
                ->selectRaw('conversation_id, COUNT(*) as cnt')
                ->whereIn('conversation_id', $convIds)
                ->where('direction', 'inbound')
                ->groupBy('conversation_id');

            // Per-conversation unread for THIS user. Two rules:
            //   1. Inbound message is newer than my last_read_at for this convo.
            //   2. No staff has replied AFTER this inbound message. Any outbound
            //      (by anyone) effectively marks prior inbounds as "handled" for
            //      all staff — this is the "reply = read for everyone" rule.
            if ($hasReadsTable && $userId) {
                $msgQuery = DB::table('t_wa_messages as m')
                    ->selectRaw('m.conversation_id, COUNT(*) as cnt')
                    ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                        $j->on('r.conversation_id', '=', 'm.conversation_id')
                          ->where('r.user_id', '=', $userId);
                    })
                    ->whereIn('m.conversation_id', $convIds)
                    ->where('m.direction', 'inbound')
                ->where('m.created_at', '>=', \App\Models\FIN\ConfigModel::get('wa_unread_floor_date', '2026-06-28'))
                    ->where(function ($w) {
                        $w->whereNull('r.last_read_at')
                          ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
                    })
                    ->whereRaw('NOT EXISTS (
                        SELECT 1 FROM t_wa_messages m2
                        WHERE m2.conversation_id = m.conversation_id
                          AND m2.direction = \'outbound\' AND m2.sent_by IS NOT NULL
                          AND m2.created_at > m.created_at
                    )');

                // System-answered taps don't count as unread. See UnreadQuery.
                \App\Services\WhatsApp\UnreadQuery::exclude($msgQuery);

                // Super-reader's global_read_at — inbound messages older
                // than this are read for everyone.
                if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                    $msgQuery->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                             ->where(function ($w) {
                                 $w->whereNull('c.global_read_at')
                                   ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                             });
                }

                // Limited users shouldn't see unread counts for messages they
                // aren't allowed to read. Mirror the visible-window filter.
                if ($access['limited']) {
                    $msgQuery->where('m.created_at', '>=', $access['cutoff']);
                }

                $rows = $msgQuery->groupBy('m.conversation_id')->get();
                foreach ($rows as $r) {
                    $unreadByConv[$r->conversation_id] = (int) $r->cnt;
                }

                // Post-process: any conversation the user has explicitly
                // Mark-Unread-ed gets at least 1 unread, even if there are
                // no eligible inbound messages left after the outbound
                // filter. Cleared automatically on the next markRead.
                if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                    $forcedIds = DB::table('t_wa_conversation_reads')
                        ->where('user_id', $userId)
                        ->whereIn('conversation_id', $convIds)
                        ->whereNotNull('forced_unread_at')
                        ->pluck('conversation_id');
                    foreach ($forcedIds as $fid) {
                        if (empty($unreadByConv[$fid])) {
                            $unreadByConv[$fid] = 1;
                        }
                    }
                }
            } else {
                // Fallback: legacy unread_count column per conversation.
                foreach ($convs as $c) {
                    $unreadByConv[$c->id] = (int) $c->unread_count;
                }
            }
        }

        // Last message preview per conversation in one grouped query.
        $lastMsgByConv = [];
        if (!empty($convIds)) {
            $latestIds = DB::table('t_wa_messages')
                ->selectRaw('MAX(id) as id, conversation_id')
                ->whereIn('conversation_id', $convIds)
                ->groupBy('conversation_id')
                ->pluck('id');
            if ($latestIds->isNotEmpty()) {
                $lastMsgByConv = MessageModel::whereIn('id', $latestIds)
                    ->get()
                    ->keyBy('conversation_id');
            }
        }

        // Failed-send indicator: per conversation, flag the row if its most
        // recent outbound message (last 7 days) has status='failed'. This
        // surfaces send-failures on the inbox without forcing the operator
        // to open the chat to discover them. We deliberately scope to the
        // *latest* outbound (not "any failed") — once the operator retries
        // and the next send succeeds, the indicator clears automatically.
        $failedByConv = [];
        if (!empty($convIds)) {
            $cutoff = now()->subDays(7);
            $latestOutboundIds = DB::table('t_wa_messages')
                ->selectRaw('MAX(id) as id, conversation_id')
                ->whereIn('conversation_id', $convIds)
                ->where('direction', 'outbound')
                ->where('created_at', '>=', $cutoff)
                ->groupBy('conversation_id')
                ->pluck('id');
            if ($latestOutboundIds->isNotEmpty()) {
                $failedRows = DB::table('t_wa_messages')
                    ->whereIn('id', $latestOutboundIds)
                    ->where('status', 'failed')
                    ->get(['conversation_id', 'error_message', 'template_name', 'created_at']);
                foreach ($failedRows as $fr) {
                    $failedByConv[$fr->conversation_id] = [
                        'failed_at'      => $fr->created_at,
                        'error_message'  => $fr->error_message ?: 'Send failed',
                        'template_name'  => $fr->template_name,
                    ];
                }
            }
        }

        // Labels: one batched query, keyed by conversation_id. Also counts
        // per-conversation unread @mentions for the current viewer (Phase 2).
        $labelsByConv = [];
        $mentionsByConv = [];
        // Mirrors the API controller — see WhatsAppController::MENTION_PIN_HOURS
        // for the full rationale. A recent, unread team tag pins the chat to
        // the top of the tagged user's inbox until they open it.
        $pinByConv = [];
        $hasLabelsTable = Schema::hasTable('t_wa_labels') && Schema::hasTable('t_wa_conversation_labels');
        $hasSeenCol = $hasLabelsTable && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
        if ($hasLabelsTable && !empty($convIds)) {
            $cols = ['cl.conversation_id', 'l.id', 'l.name', 'l.color', 'l.user_id',
                     'cl.applied_at', 'cl.applied_by', 'u.fullname as applied_by_name'];
            if ($hasSeenCol) $cols[] = 'cl.mention_seen_at';
            $labelRows = DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                // fullname, NOT name — t_sys_user has no `name` column.
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'cl.applied_by')
                ->whereIn('cl.conversation_id', $convIds)
                ->orderBy('l.name')
                ->get($cols);
            $pinCutoff = now()->subHours(\App\Http\Controllers\API\WhatsAppController::MENTION_PIN_HOURS);
            foreach ($labelRows as $lr) {
                $labelsByConv[$lr->conversation_id][] = [
                    'id'      => (int) $lr->id,
                    'name'    => $lr->name,
                    'color'   => $lr->color,
                    'user_id' => $lr->user_id ? (int) $lr->user_id : null,
                ];
                if ($hasSeenCol
                    && $lr->user_id
                    && (int) $lr->user_id === (int) $userId
                    && empty($lr->mention_seen_at)) {
                    $mentionsByConv[$lr->conversation_id] =
                        ($mentionsByConv[$lr->conversation_id] ?? 0) + 1;

                    $appliedAt = $lr->applied_at ? \Carbon\Carbon::parse($lr->applied_at) : null;
                    if ($appliedAt && $appliedAt->gte($pinCutoff)) {
                        $existing = $pinByConv[$lr->conversation_id] ?? null;
                        if (!$existing || $appliedAt->gt(\Carbon\Carbon::parse($existing['applied_at']))) {
                            $pinByConv[$lr->conversation_id] = [
                                'label'      => $lr->name,
                                'by'         => $lr->applied_by_name ?: 'a teammate',
                                'applied_at' => $appliedAt->toIso8601String(),
                                'ago'        => $appliedAt->diffForHumans(null, true) . ' ago',
                            ];
                        }
                    }
                }
            }
        }

        // Jun-2026 — Payment-proof flag per customer (does this customer have a
        // matched WhatsApp screenshot / bank email?). Bulk lookup, no N+1.
        $proofByCustomer = [];
        if (config('payment_signals.enabled') && Schema::hasTable('t_fin_payment_signal')) {
            $custIds = $convs->pluck('customer_id')->filter()->unique()->values()->all();
            if (!empty($custIds)) {
                $rows = DB::table('t_fin_payment_signal')
                    ->whereIn('matched_customer_id', $custIds)
                    ->whereIn('status', ['matched', 'amount_mismatch'])
                    ->get(['id', 'matched_customer_id', 'matched_order_id', 'source', 'status', 'paired_signal_id']);
                // Drop signals whose order's online approval is already approved —
                // the badge is an action prompt, not a permanent flag.
                $settledOrders = array_flip(
                    \App\Services\Payments\Signals\PaymentProofStatusService::settledOrderIds(
                        $rows->pluck('matched_order_id')->all()
                    )
                );
                $proofRowsByCustomer = [];
                foreach ($rows as $r) {
                    if ($r->matched_order_id !== null && isset($settledOrders[(int) $r->matched_order_id])) {
                        continue;
                    }
                    $c = $r->matched_customer_id;
                    $proofByCustomer[$c] ??= ['wa' => false, 'email' => false, 'sms' => false, 'matched' => false, 'mismatch' => false, 'verified_pair' => false];
                    if ($r->source === 'whatsapp') $proofByCustomer[$c]['wa'] = true;
                    if ($r->source === 'email')    $proofByCustomer[$c]['email'] = true;
                    if ($r->source === 'bank_sms') $proofByCustomer[$c]['sms'] = true;
                    if ($r->status === 'matched')  $proofByCustomer[$c]['matched'] = true;
                    if ($r->status === 'amount_mismatch') $proofByCustomer[$c]['mismatch'] = true;
                    $proofRowsByCustomer[$c][(int) $r->id] = $r;
                }
                // "Verified" needs a GENUINE cross-source pair (mirrors
                // PaymentProofStatusService::summarise): a WhatsApp row bound to a
                // BANK-side row (email OR a bank credit SMS from NF Messages — both
                // are the bank's word) both ways, at least one matched. Pairing
                // already enforces same amount + same receiving bank, so a stray
                // bank credit in the customer's history can't green the inbox.
                foreach ($proofRowsByCustomer as $c => $custRows) {
                    foreach ($custRows as $r) {
                        if ($r->source !== 'whatsapp' || empty($r->paired_signal_id)) {
                            continue;
                        }
                        $mate = $custRows[(int) $r->paired_signal_id] ?? null;
                        if ($mate && in_array($mate->source, ['email', 'bank_sms'], true)
                            && (int) $mate->paired_signal_id === (int) $r->id
                            && ($r->status === 'matched' || $mate->status === 'matched')) {
                            $proofByCustomer[$c]['verified_pair'] = true;
                            break;
                        }
                    }
                }
            }
        }

        // Aug-2026 — which of these customers has a location pin sitting unused?
        // ONE batched, indexed lookup for the whole page (never per row), exactly
        // like the payment-proof map above. Empty array if the audit table isn't
        // on this environment yet, so the inbox degrades to "no badges".
        $pinPendingByCustomer = [];
        try {
            $pinPendingByCustomer = app(\App\Services\Location\PinHistoryService::class)
                ->pendingReplies($convs->pluck('customer_id')->filter()->unique()->values()->all());
        } catch (\Throwable $e) {
            Log::debug('getConversations: pin-pending map skipped', ['error' => $e->getMessage()]);
        }

        $conversations = $convs->map(function ($conv) use ($unreadByConv, $lastMsgByConv, $hasQurbaniCol, $matchByConvId, $labelsByConv, $mentionsByConv, $pinByConv, $failedByConv, $proofByCustomer, $pinPendingByCustomer) {
            $lastMsg = $lastMsgByConv[$conv->id] ?? null;
            $unread = $unreadByConv[$conv->id] ?? 0;
            $failed = $failedByConv[$conv->id] ?? null;

            // Payment-proof badge payload for this conversation's customer.
            $proof = null;
            $pf = $conv->customer_id ? ($proofByCustomer[$conv->customer_id] ?? null) : null;
            if ($pf) {
                if (!empty($pf['verified_pair'])) {
                    $pStatus = \App\Services\Payments\Signals\PaymentProofStatusService::VERIFIED;
                } elseif ($pf['mismatch'] && !$pf['matched']) {
                    $pStatus = \App\Services\Payments\Signals\PaymentProofStatusService::AMOUNT_MISMATCH;
                } elseif ($pf['wa']) {
                    $pStatus = \App\Services\Payments\Signals\PaymentProofStatusService::PROOF_RECEIVED;
                } elseif ($pf['email'] || $pf['sms']) {
                    $pStatus = \App\Services\Payments\Signals\PaymentProofStatusService::BANK_CONFIRMED;
                } else {
                    $pStatus = \App\Services\Payments\Signals\PaymentProofStatusService::NONE;
                }
                if ($pStatus !== \App\Services\Payments\Signals\PaymentProofStatusService::NONE) {
                    $proof = [
                        'status'       => $pStatus,
                        'label'        => \App\Services\Payments\Signals\PaymentProofStatusService::label($pStatus),
                        'color'        => \App\Services\Payments\Signals\PaymentProofStatusService::color($pStatus),
                        'has_whatsapp' => $pf['wa'],
                        'has_email'    => $pf['email'],
                        'has_sms'      => $pf['sms'],
                    ];
                }
            }

            // Chat-mode extras: when this conversation was pulled in by a
            // content search we ship back the matched snippet + how many
            // messages matched so the UI can render it inline.
            $match = $matchByConvId[$conv->id] ?? null;

            return [
                'id' => $conv->id,
                'customer_id' => $conv->customer_id,
                'customer_name' => $conv->customer ? $conv->customer->full_name : ($conv->wa_contact_name ?: $conv->wa_phone),
                'customer_city' => $conv->customer?->city ?? '',
                'wa_phone' => $conv->wa_phone,
                'status' => $conv->status,
                'unread_count' => $unread,
                'is_qurbani' => $hasQurbaniCol ? (bool) $conv->is_qurbani : false,
                'qurbani_flag_reason' => $hasQurbaniCol ? ($conv->qurbani_flag_reason ?? null) : null,
                'last_message_at' => $conv->last_message_at,
                'last_message_preview' => $lastMsg?->content ? \Illuminate\Support\Str::limit($lastMsg->content, 80) : ($lastMsg?->type ?? ''),
                'last_message_direction' => $lastMsg?->direction ?? '',
                'match_snippet' => $match['snippet'] ?? null,
                'match_count' => $match['count'] ?? null,
                'match_direction' => $match['snippet_direction'] ?? null,
                'session_active' => $conv->isSessionActive(),
                'session_expires_at' => $conv->last_customer_message_at
                    ? $conv->last_customer_message_at->addHours(24)->toIso8601String()
                    : null,
                'labels' => $labelsByConv[$conv->id] ?? [],
                // Phase 2: unread @mentions targeted at the viewer.
                'mentions_count' => (int) ($mentionsByConv[$conv->id] ?? 0),
                // Present ⇒ pins to the top for THIS viewer until they open it.
                'pinned_mention' => $pinByConv[$conv->id] ?? null,
                // Apr-2026: failed-send indicator on inbox rows.
                'last_send_failed' => $failed !== null,
                'last_send_error'  => $failed['error_message'] ?? null,
                'last_send_template' => $failed['template_name'] ?? null,
                // Jun-2026: payment-proof badge (null when none).
                'payment_proof' => $proof,
                // Aug-2026: a location pin from this customer we did not save and
                // nobody has resolved. Null for everyone else.
                'pin_reply_pending' => $conv->customer_id
                    ? ($pinPendingByCustomer[(int) $conv->customer_id] ?? null)
                    : null,
            ];
        });

        // Pinned tags lead, newest first; everything else keeps its normal
        // last_message_at order. Stable sort on the built rows (see the API
        // controller for the rationale) — once read the pin is null and the row
        // simply returns to its natural position.
        $conversations = $conversations->sortByDesc(function ($c) {
            return empty($c['pinned_mention'])
                ? 0
                : strtotime($c['pinned_mention']['applied_at'] ?? 'now');
        }, SORT_NUMERIC)->values();

        // "Unread" filter applied after compute so it uses per-user counts.
        if ($request->filter === 'unread') {
            $conversations = $conversations->filter(fn($c) => ($c['unread_count'] ?? 0) > 0)->values();
        }

        // Phase 2: "@me" filter — only conversations with unread mentions
        // targeted at the current user.
        if ($request->boolean('assigned_to_me')) {
            $conversations = $conversations->filter(fn($c) => ($c['mentions_count'] ?? 0) > 0)->values();
        }

        // Optional filter by label id — driven by the label filter pill
        // on the inbox. Applied after compute so it honours the "labels"
        // field we just built.
        if ($labelFilter = $request->input('label_id')) {
            $labelFilter = (int) $labelFilter;
            $conversations = $conversations->filter(function ($c) use ($labelFilter) {
                foreach ($c['labels'] ?? [] as $l) {
                    if ((int) $l['id'] === $labelFilter) return true;
                }
                return false;
            })->values();
        }

        // Pagination cursor for "Load more chats". The frontend only needs
        // a value to pass back as `before_last_message_at`; page-numbering
        // is intentionally avoided because conversations re-order whenever
        // new activity bubbles them up. We expose `has_more = true` only
        // when the fetched batch was full — otherwise we know we hit the
        // tail and the button can hide. Note this is computed BEFORE the
        // post-fetch filters (unread/@me/label) since those just hide rows
        // client-side; older conversations matching the same filters might
        // still exist and the button should still be available.
        $nextCursor = null;
        $hasMore    = $convs->count() >= $perPage;
        if ($hasMore && !$convs->isEmpty()) {
            $oldest = $convs->last();
            $nextCursor = $oldest->last_message_at
                ? \Carbon\Carbon::parse($oldest->last_message_at)->toIso8601String()
                : null;
        }

        // Unread mode is single-shot (no cursor pagination), so unconditionally
        // close out has_more. Also surface the DB-wide unread total so the
        // confirm dialog on Mark-All-Read can quote a real number to the
        // operator before they pull the trigger.
        if ($unreadOnly) {
            $hasMore = false;
            $nextCursor = null;
        }

        $resp = [
            'success' => true,
            'conversations' => $conversations,
            'is_limited' => $access['limited'],
            'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
            'search_mode' => $searchMode,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'is_paged_fetch' => $isPagedFetch,
        ];
        if ($unreadOnly) {
            $resp['total_unread'] = isset($unreadIds) ? count($unreadIds) : 0;
        }
        return response()->json($resp);
    }

    /**
     * Build a short, UI-friendly snippet around the first occurrence of the
     * search term inside a message body. Returned unescaped — the frontend
     * escapes + highlights the match span. Keeps ~40 chars of context on
     * either side so operators can recognise the conversation at a glance
     * without leaking the whole message into the sidebar.
     */
    private function buildChatSnippet(string $content, string $needle): string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));
        if ($needle === '' || $content === '') {
            return \Illuminate\Support\Str::limit($content, 90);
        }
        $pos = mb_stripos($content, $needle);
        if ($pos === false) {
            return \Illuminate\Support\Str::limit($content, 90);
        }
        $padding = 40;
        $start = max(0, $pos - $padding);
        $length = mb_strlen($needle) + ($padding * 2);
        $slice = mb_substr($content, $start, $length);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($content) ? '…' : '';
        return $prefix . $slice . $suffix;
    }

    public function getMessages(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $conversation = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city,latitude,longitude,verified_location_url')->findOrFail($conversationId);

        // Limited users cannot access conversations whose most recent activity
        // is older than the cutoff. Returning 404 (not 403) so the UI treats
        // them as "not visible" consistently with the list-level filter above.
        if ($access['limited'] && $conversation->last_message_at && $conversation->last_message_at->lt($access['cutoff'])) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        // Lazy auto-link: if this conversation has no linked customer yet, try
        // to find one by phone. This handles the case where the customer was
        // created AFTER the WhatsApp conversation already existed (otherwise
        // findOrCreateConversation only links once at creation time).
        if (!$conversation->customer_id) {
            $linkedId = app(WhatsAppService::class)->relinkConversationCustomer($conversation);
            if ($linkedId) {
                $conversation->load('customer:id,first_name,last_name,phone_normalized,city,latitude,longitude,verified_location_url');
            }
        }

        // May-2026 bugfix — used to be ORDER BY created_at ASC LIMIT 51,
        // which on a conversation with >50 messages returned the OLDEST 50
        // and silently dropped the NEWEST messages off the bottom of the
        // chat panel (reported: Ali Nizami's "Wow" / "Order kahan hai mera"
        // invisible in /messages even though they showed in the timeline
        // drawer and in the mobile chat — that conversation has 58
        // messages total, so messages #51-58 — the latest 8 — never made
        // it into the JSON payload).
        //
        // Fix mirrors the sibling mobile endpoint
        // (App\Http\Controllers\API\WhatsAppController::getMessages):
        //   • ORDER BY created_at DESC + LIMIT (N+1) gets the latest N
        //     messages (plus 1 sentinel row used only to decide whether
        //     a "Load older messages" link should render).
        //   • ->reverse()->values() flips back to chronological order so
        //     the front-end renderer (which already walks msgs.forEach,
        //     pushes date-separators in forward order, and scrolls to
        //     scrollHeight at the end) keeps working unchanged.
        //   • The `before` query-string param keeps its existing
        //     contract — "give me 50 messages with id < before" — so
        //     the new loadOlderMessages() pagination just walks the
        //     id space backwards exactly like the mobile screen does.
        $query = MessageModel::where('conversation_id', $conversationId);

        // And within an allowed conversation, limited users still only see
        // messages from today + yesterday.
        if ($access['limited']) {
            $query->where('created_at', '>=', $access['cutoff']);
        }

        if ($request->before) {
            $query->where('id', '<', $request->before);
        }

        $limit = $request->limit ?? 50;
        $messagesDesc = $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc') // tie-breaker — two messages can land in the same second
            ->limit($limit + 1)
            ->get();
        $hasMore = $messagesDesc->count() > $limit;
        if ($hasMore) {
            $messagesDesc = $messagesDesc->slice(0, $limit);
        }
        $messages = $messagesDesc->reverse()->values();

        // Sep-2026 — resolve what each reaction on this page was reacting to.
        // Shared implementation (API\WhatsAppController::getMessages calls the
        // same method) so the web chat and the phone quote it identically.
        $reactionCtx = app(WhatsAppService::class)->reactionContextFor($messages, (int) $conversationId);

        $totalOrders = $conversation->customer_id
            ? \App\Models\CRM\OrderModel::where('customer_id', $conversation->customer_id)->count()
            : 0;

        // Build "seen by" list: who has read this conversation (excluding me) + when.
        $seenBy = [];
        if (Schema::hasTable('t_wa_conversation_reads')) {
            $currentUserId = auth()->id();
            $seenBy = ConversationReadModel::where('conversation_id', $conversation->id)
                ->when($currentUserId, fn($q) => $q->where('user_id', '!=', $currentUserId))
                ->orderByDesc('last_read_at')
                ->limit(10)
                ->get()
                ->map(function ($r) {
                    // Our custom User model lives on `t_sys_user` where the
                    // display column is `fullname` (not Laravel's default `name`),
                    // so `$user->name` is always null — falling back to
                    // "User #<id>" which looked awful in the UI.
                    $user = \App\Models\User::find($r->user_id);
                    return [
                        'user_id' => $r->user_id,
                        'name' => $user?->fullname ?? $user?->email ?? ('User #' . $r->user_id),
                        'last_read_at' => $r->last_read_at?->toIso8601String(),
                    ];
                })->all();
        }

        $hasQurbaniCol = Schema::hasColumn('t_wa_conversations', 'is_qurbani');
        $convLabels = $this->labelsForConversation($conversation->id);

        // Defensive implicit mark-as-read on chat-open / poll. The explicit
        // POST /mark-read endpoint runs the same write but is fire-and-forget
        // on the client; if it races the inbox refresh (or fails silently)
        // a previously "Marked Unread" conversation can stay forced-unread
        // forever even though the user clearly opened the chat. Doing it
        // here too is a belt-and-suspenders guarantee — by the time we
        // return the messages payload the per-user read row is already
        // updated, so the next inbox load can never report this conv as
        // unread for the viewing user. We deliberately skip the WhatsApp
        // blue-tick receipt API here; that side-effect stays on the
        // explicit POST so we don't double-spend it on every poll.
        $userId = auth()->id();
        if ($userId && !$request->before && Schema::hasTable('t_wa_conversation_reads')) {
            try {
                $payload = ['last_read_at' => now()];
                if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                    $payload['forced_unread_at'] = null;
                }
                ConversationReadModel::updateOrCreate(
                    ['user_id' => $userId, 'conversation_id' => $conversation->id],
                    $payload
                );
                ConversationModel::where('id', $conversation->id)->update(['unread_count' => 0]);
            } catch (\Exception $e) {
                Log::debug('getMessages: implicit markRead skipped', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'customer_name' => $conversation->customer ? $conversation->customer->full_name : ($conversation->wa_contact_name ?: $conversation->wa_phone),
                'customer_city' => $conversation->customer?->city ?? '',
                'customer_orders' => $totalOrders,
                // Aug-2026 — does this customer ALREADY have a verified pin? Kept
                // for context only. ⚠ It must NOT be used to decide what the chat
                // says about a location reply: since the re-request rule landed, a
                // reply to a manual re-request DOES overwrite, so "has a pin" no
                // longer implies "was not saved". Use location_verdicts below.
                'customer_has_pin' => $conversation->customer
                    ? ((
                        $conversation->customer->latitude && $conversation->customer->longitude
                        && (float) $conversation->customer->latitude !== 0.0
                        && (float) $conversation->customer->longitude !== 0.0
                       ) || !empty($conversation->customer->verified_location_url))
                    : false,
                // What actually happened to each pin this customer sent — saved,
                // saved-as-a-replacement, or refused (and why). The client matches
                // these to location bubbles by time and stays silent without a
                // match, so it can never invent an outcome.
                'location_verdicts' => $conversation->customer_id
                    ? app(\App\Services\Location\PinHistoryService::class)
                        ->replyVerdicts((int) $conversation->customer_id)
                    : [],
                // The unresolved one, for the header bar.
                'pin_reply_pending' => $conversation->customer_id
                    ? app(\App\Services\Location\PinHistoryService::class)
                        ->pendingReply((int) $conversation->customer_id)
                    : null,
                'wa_phone' => $conversation->wa_phone,
                'status' => $conversation->status,
                'unread_count' => 0,
                'is_qurbani' => $hasQurbaniCol ? (bool) $conversation->is_qurbani : false,
                'qurbani_flag_reason' => $hasQurbaniCol ? ($conversation->qurbani_flag_reason ?? null) : null,
                'session_active' => $conversation->isSessionActive(),
                'session_expires_at' => $conversation->last_customer_message_at
                    ? $conversation->last_customer_message_at->addHours(24)->toIso8601String()
                    : null,
                'seen_by' => $seenBy,
                'labels' => $convLabels,
                // 🔴⏳ Oct-2026 — tag follow-up strip for the person who TAGGED on this chat
                // ("⏳ Taimur hasn't opened this · 25 min"); null for everyone else.
                'followup' => app(\App\Services\WhatsApp\MentionFollowupService::class)
                    ->stripForViewer((int) $conversation->id, (int) (auth()->id() ?? 0)),
            ],
            'messages' => $messages->map(function ($msg) use ($reactionCtx) {
                return [
                    'id' => $msg->id,
                    // Sep-2026 — the message this reaction was stuck onto (null
                    // for everything else). Same shared resolver the phone uses.
                    'reacted_to' => $reactionCtx[$msg->id] ?? null,
                    'direction' => $msg->direction,
                    'type' => $msg->type,
                    'content' => $msg->content,
                    'template_name' => $msg->template_name,
                    'media_url' => $msg->media_public_url,
                    'media_mime_type' => $msg->media_mime_type,
                    'status' => $msg->status,
                    'error_message' => $msg->error_message,
                    'sender_name' => $msg->sender?->name ?? null,
                    'metadata' => $msg->metadata ? json_decode($msg->metadata, true) : null,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            })->values(),
            'has_more' => $hasMore,
            'is_limited' => $access['limited'],
            'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
        ]);
    }

    public function sendMessage(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string|max:4096']);

        $conversation = ConversationModel::findOrFail($conversationId);
        $service = app(WhatsAppService::class);

        if (!$service->isSessionActive($conversation)) {
            return response()->json([
                'success' => false,
                'session_expired' => true,
                'message' => 'The 24-hour messaging window has expired. Please use a template message.',
            ], 422);
        }

        $result = $service->sendTextMessage($conversation->wa_phone, $request->message);

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 422);
        }

        $service->saveOutboundMessage($conversation->id, $result, 'text', $request->message, auth()->id());
        return response()->json(['success' => true]);
    }

    public function sendTemplate(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'template_name' => 'required',
            'conversation_id' => 'nullable|integer',
            'order_id' => 'nullable|integer',
            // Aug-2026: stamp send history without triggering the invoice-image
            // attach that order_id implies (see resolveRelatedOrderNumber).
            'related_order_number' => 'nullable|string|max:50',
            // Sep-2026: every order a MULTI-invoice reminder covers. Recorded in
            // metadata, because related_order_number can only name one of them.
            'related_order_numbers'   => 'nullable|array|max:50',
            'related_order_numbers.*' => 'string|max:50',
        ]);

        $service = app(WhatsAppService::class);
        $bodyParams = $request->body_params ?? [];
        $headerParams = $request->header_params ?? [];

        // Jul-2026: resolve the DIAL number instead of blind PK-formatting.
        // resolveDialPhone prefers a proven known number (conversation
        // wa_phone / international phone_original) and is a no-op for PK
        // numbers. phone_exact=true is the MANUAL OVERRIDE seam (used by the
        // NF Messages app): dial exactly the digits given, no normalization.
        // See WHATSAPP-PHONE-HANDLING.md at the workspace root.
        $conversation = $request->conversation_id
            ? ConversationModel::find($request->conversation_id)
            : null;
        $phone = $request->boolean('phone_exact')
            ? preg_replace('/\D/', '', (string) $request->phone)
            : $service->resolveDialPhone((string) $request->phone, $conversation);
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        // Marketing-dedup guard. Returns null if OK to send, or a payload
        // describing the recent prior send (which we relay as 409 Conflict
        // so the chat UI shows the confirm dialog).
        $dedup = $service->enforceMarketingDedup(
            $request->conversation_id ? (int) $request->conversation_id : null,
            $phone,
            $request->template_name,
            auth()->user(),
            $force
        );
        if ($dedup) {
            return response()->json(['success' => false] + $dedup, 409);
        }

        // Robust invoice attach (Jul-2026): an invoice-bearing reminder passes
        // order_id. Upload the captured PNG to Meta and attach it BY media_id
        // (buildInvoiceHeaderForSend) instead of trusting a /public-storage
        // link header — Meta's fetch of that link 403s on this host (error
        // 131053 "Media upload error ... 403 Forbidden") and fails the whole
        // send. This is the same robust path the orders-page Send-Invoice and
        // the mobile send-template already use; it OVERRIDES any client-sent
        // header. Only fires when order_id is present, so plain template sends
        // (no order_id) are unaffected.
        if ($request->filled('order_id')) {
            try {
                $imgData = $this->buildInvoiceHeaderForSend((int) $request->input('order_id'));
                if (($imgData['success'] ?? false) && !empty($imgData['header_params'])) {
                    $headerParams = $imgData['header_params'];
                }
            } catch (\Exception $e) {
                \Log::warning('sendTemplate: failed to auto-attach invoice image by media_id', [
                    'order_id' => $request->input('order_id'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Jul-2026: templates that carry a FIXED media header (e.g. the app
        // launch banner) attach it automatically. A media-header template must
        // send its image every time or Meta rejects it with 132012 (verified), and
        // in the chat UI supplies one — only the invoice path above did, and
        // only for order-linked sends. Deliberately does not override a header
        // the caller already set (the invoice image wins).
        if (empty($headerParams)) {
            $headerParams = $service->headerParamsForTemplate($request->template_name);
        }

        $result = $service->sendTemplateMessage($phone, $request->template_name, 'en', $bodyParams, $headerParams);

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send template'], 422);
        }

        $conversation = $conversation ?: $service->findOrCreateConversation($phone);

        if ($conversation) {
            $paramText = implode(', ', $bodyParams);
            $service->saveOutboundMessage(
                $conversation->id,
                $result,
                'template',
                "Template: {$request->template_name}" . ($paramText ? " ({$paramText})" : ''),
                auth()->id(),
                $request->template_name,
                $bodyParams,
                $force && $service->canOverrideMarketingDedup(auth()->user()),
                $service->resolveRelatedOrderNumber(
                    $request->input('related_order_number'),
                    $request->input('order_id')
                ),
                $request->input('related_order_numbers')
            );
        }
        return response()->json(['success' => true]);
    }

    /**
     * Pre-flight check for the chat-window template picker. Lets the UI
     * decide BEFORE the user fills in template variables whether a confirm
     * dialog should appear. Returns the same payload shape as the 409
     * blocked response from sendTemplate, so the UI can reuse one renderer.
     *
     * Always returns HTTP 200 — `recently_sent=false` means "go ahead",
     * `recently_sent=true` means "show the confirm".
     */
    public function templateRecentSendCheck(Request $request)
    {
        $request->validate([
            'conversation_id' => 'nullable|integer',
            'phone'           => 'nullable|string',
            'template_name'   => 'required|string',
        ]);

        $service = app(WhatsAppService::class);
        $filter  = app(\App\Services\CampaignFilterService::class);
        $window  = $service->getMarketingDedupWindowDays();
        $isMarketing = $filter->isMarketingTemplate($request->template_name);

        $base = [
            'success'       => true,
            'is_marketing'  => $isMarketing,
            'window_days'   => $window,
            'recently_sent' => false,
            'feature_enabled' => $window > 0,
        ];

        if ($window <= 0 || !$isMarketing) {
            return response()->json($base);
        }

        // Resolve conversation by id, or by phone as a fallback. Uses the
        // dial-resolved number so this preflight matches what sendTemplate
        // will actually dial (matters for international customers).
        $conversationId = $request->conversation_id ? (int) $request->conversation_id : 0;
        if (!$conversationId && $request->filled('phone')) {
            $phone = $service->resolveDialPhone((string) $request->phone);
            $conv = ConversationModel::where('wa_phone', $phone)->first();
            if ($conv) $conversationId = (int) $conv->id;
        }
        if (!$conversationId) {
            return response()->json($base);
        }

        $hit = $filter->recentMarketingTemplateSendForConversation(
            $conversationId,
            $request->template_name,
            $window
        );
        if (!$hit) {
            return response()->json($base);
        }

        $canOverride = $service->canOverrideMarketingDedup(auth()->user());

        return response()->json($base + [
            'recently_sent'         => true,
            'reason'                => 'recent_marketing_send',
            'template_name'         => $hit['template_name'],
            'template_display_name' => $hit['template_display_name'],
            'sent_at'               => $hit['sent_at'],
            'sent_at_human'         => $hit['sent_at_human'],
            'can_override'          => $canOverride,
            'message'               => $canOverride
                ? sprintf(
                    'This customer was sent the marketing template "%s" %s. Send it again?',
                    $hit['template_display_name'],
                    $hit['sent_at_human']
                  )
                : sprintf(
                    'This customer was sent the marketing template "%s" %s. Re-sending the same marketing template within %d days is not allowed for your role.',
                    $hit['template_display_name'],
                    $hit['sent_at_human'],
                    $hit['window_days']
                  ),
        ]);
    }

    /**
     * Powers the pinned "marketing template sent recently" strip in the
     * chat header. Returns the marketing-category templates sent to this
     * conversation's customer in the configured window, newest first.
     */
    public function recentMarketingTemplates(Request $request, $conversationId)
    {
        $service = app(WhatsAppService::class);
        $window  = $service->getMarketingDedupWindowDays();

        if ($window <= 0) {
            return response()->json([
                'success'         => true,
                'feature_enabled' => false,
                'window_days'     => 0,
                'templates'       => [],
            ]);
        }

        $templates = app(\App\Services\CampaignFilterService::class)
            ->recentMarketingTemplatesForConversation((int) $conversationId, $window);

        return response()->json([
            'success'         => true,
            'feature_enabled' => true,
            'window_days'     => $window,
            'templates'       => $templates,
        ]);
    }

    public function markRead(Request $request, $conversationId)
    {
        $userId = auth()->id();
        $user = auth()->user();
        $now = now();

        // Per-user read state (new model). Always clear forced_unread_at on
        // markRead — opening the chat is the explicit signal that the user
        // no longer wants this conv forced to the top of the unread list.
        if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
            $payload = ['last_read_at' => $now];
            if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                $payload['forced_unread_at'] = null;
            }
            ConversationReadModel::updateOrCreate(
                ['user_id' => $userId, 'conversation_id' => $conversationId],
                $payload
            );
        }

        // Clear unread @mentions for this user on this conversation (Phase 2).
        if ($userId && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
            DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                ->where('cl.conversation_id', $conversationId)
                ->where('l.user_id', $userId)
                ->whereNull('cl.mention_seen_at')
                ->update(['cl.mention_seen_at' => $now]);
        }

        // 🔴⏳ Oct-2026: opening the chat on the WEB ends the tag follow-up too (and clears the
        // sticky alert on his phone). Instant return for anyone not on the tag-alert list.
        if ($userId) {
            app(\App\Services\WhatsApp\MentionFollowupService::class)->onOpened((int) $conversationId, (int) $userId);
        }

        // Legacy column is still zeroed out so global badge sums stay sane.
        // (Push notifications and the mobile badge use this to avoid loading
        // the full per-user matrix.)
        $updates = ['unread_count' => 0];

        // Super-reader (Taimur role) — reading marks read for everyone.
        if (app(WhatsAppService::class)->isSuperReader($user) &&
            Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $updates['global_read_at'] = $now;
        }
        ConversationModel::where('id', $conversationId)->update($updates);

        // Push blue-ticks to the customer — now that a human actually saw it.
        try {
            app(WhatsAppService::class)->markInboundAsReadOnWhatsApp((int) $conversationId);
        } catch (\Exception $e) {
            Log::debug('markRead: WA receipt failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Bulk "Mark all unread conversations as read" — super-reader only.
     *
     * Apr-2026. Background: the inbox accumulated a long tail of stale
     * one-message threads from before staff started replying systematically.
     * For ordinary operators those still need to be cleared one by one
     * (their Mark-as-Read is a personal action), but the Taimur role has the
     * `whatsapp_super_reader` permission, which means a single action of
     * theirs is global — anything they read is read for EVERY user. This
     * endpoint is the bulk version of that: stamp `global_read_at = NOW()`
     * on every conversation that's currently unread for this super-reader,
     * inside one transaction.
     *
     * Behaviour decisions (kept deliberately narrow):
     *  - Hard 403 for non-super-readers. There is no per-user equivalent —
     *    the user explicitly asked that everyone else clear individually.
     *  - We use unreadConversationIdsForCurrentUser() so the candidate set
     *    matches exactly what the operator sees in the Unread tab and the
     *    sidebar badge. No "approximate match" surprises.
     *  - We do NOT push WhatsApp blue ticks for the bulk action. Doing so
     *    would fire one outbound API call per conversation (potentially
     *    hundreds), spam the customer with read receipts on threads they
     *    long since forgot about, and risk rate-limit hits. Individual
     *    Mark-as-Read still pushes blue ticks; bulk does not.
     *  - Idempotent: re-running with no unread is a no-op returning 0.
     */
    public function markAllRead(Request $request)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $user = auth()->user();
        if (!app(WhatsAppService::class)->isSuperReader($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Mark-all-read is restricted to super-readers.',
            ], 403);
        }

        if (!Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            return response()->json([
                'success' => false,
                'message' => 'global_read_at column missing — run the labels/super-reader migration first.',
            ], 500);
        }

        $unreadIds = $this->unreadConversationIdsForCurrentUser($access);
        if (empty($unreadIds)) {
            return response()->json(['success' => true, 'cleared' => 0]);
        }

        $now = now();
        DB::transaction(function () use ($unreadIds, $now, $user) {
            // Stamp the global marker + zero the legacy column. chunk()
            // keeps the IN(...) list at a sane size for very large clears.
            foreach (array_chunk($unreadIds, 500) as $chunk) {
                ConversationModel::whereIn('id', $chunk)->update([
                    'global_read_at' => $now,
                    'unread_count'   => 0,
                ]);
            }

            // Also stamp the super-reader's own per-user read row, so the
            // forced_unread_at flag (if any) is cleared and their personal
            // last_read_at advances. Mirrors what markRead() does for a
            // single conversation.
            $userId = $user ? $user->id : null;
            if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
                $hasForced = Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at');
                $rows = [];
                foreach ($unreadIds as $cid) {
                    $row = [
                        'user_id'         => $userId,
                        'conversation_id' => $cid,
                        'last_read_at'    => $now,
                        'updated_at'      => $now,
                        'created_at'      => $now,
                    ];
                    if ($hasForced) {
                        $row['forced_unread_at'] = null;
                    }
                    $rows[] = $row;
                }
                // upsert() handles both inserts (first-time read) and
                // updates (existing row gets its last_read_at advanced).
                $updateCols = $hasForced
                    ? ['last_read_at', 'forced_unread_at', 'updated_at']
                    : ['last_read_at', 'updated_at'];
                foreach (array_chunk($rows, 500) as $chunk) {
                    ConversationReadModel::upsert(
                        $chunk,
                        ['user_id', 'conversation_id'],
                        $updateCols
                    );
                }
            }
        });

        Log::info('WhatsApp mark-all-read', [
            'user_id'   => $user ? $user->id : null,
            'user_name' => $user ? $user->name : null,
            'cleared'   => count($unreadIds),
        ]);

        return response()->json([
            'success' => true,
            'cleared' => count($unreadIds),
        ]);
    }

    /**
     * Mark a conversation UNREAD for this user — and for everyone if the
     * current user is a super-reader. Mirrors the mobile API's behaviour.
     */
    public function markUnread(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $userId = auth()->id();
        $user = auth()->user();

        // Upsert the user's read row with forced_unread_at = NOW(). We do
        // NOT modify last_read_at — the existing schema has it NOT NULL
        // and the forced_unread_at flag alone is sufficient to make the
        // unread calc report the conv as unread regardless of the
        // outbound-reply filter. Cleared automatically on next markRead.
        if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
            $hasForcedCol = Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at');
            if ($hasForcedCol) {
                $existing = ConversationReadModel::where('user_id', $userId)
                    ->where('conversation_id', $conversationId)
                    ->first();
                if ($existing) {
                    $existing->forced_unread_at = now();
                    $existing->save();
                } else {
                    // First time this user touches the conv — seed a row
                    // with epoch last_read_at so the NOT NULL constraint
                    // is satisfied.
                    ConversationReadModel::create([
                        'user_id'          => $userId,
                        'conversation_id'  => $conversationId,
                        'last_read_at'     => '1970-01-01 00:00:00',
                        'forced_unread_at' => now(),
                    ]);
                }
            } else {
                // Pre-migration fallback.
                ConversationReadModel::where('user_id', $userId)
                    ->where('conversation_id', $conversationId)
                    ->delete();
            }
        }

        // Keep the legacy counter at ≥ 1 so push-badge consumers still
        // show a dot on this conversation.
        $current = (int) (ConversationModel::where('id', $conversationId)->value('unread_count') ?? 0);
        $updates = ['unread_count' => max(1, $current)];
        if (app(WhatsAppService::class)->isSuperReader($user) &&
            Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $updates['global_read_at'] = null;
        }
        ConversationModel::where('id', $conversationId)->update($updates);

        return response()->json(['success' => true]);
    }

    // =========================================================================
    // LABELS (Phase 1 + Phase 2 mention helpers)
    // =========================================================================

    /**
     * Phase 2 helper: users eligible to be @mentioned (anyone with WA view
     * permission). Gated by manage_whatsapp_labels since only admins pick
     * users from this list when creating a user-mention label.
     */
    public function getLabelUsers(Request $request)
    {
        $me = auth()->user();
        if (!$me || !method_exists($me, 'hasMobilePermission') ||
            !$me->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $rows = DB::table('t_sys_user as u')
            ->join('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->join('t_sys_role_mobile_permission as rmp', 'rmp.role_id', '=', 'ur.role_id')
            ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
            ->whereIn('mp.permission_code', ['view_whatsapp_messages', 'view_whatsapp_messages_limited'])
            ->where('u.is_active', 1)
            ->distinct()
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname', 'u.email'])
            ->map(fn ($r) => [
                'id'       => (int) $r->id,
                'fullname' => $r->fullname,
                'email'    => $r->email,
            ]);
        return response()->json(['success' => true, 'users' => $rows]);
    }

    /**
     * Phase 2 — total unread @mentions for the current user.
     */
    public function getMentionsCount(Request $request)
    {
        $me = auth()->user();
        if (!$me) return response()->json(['success' => true, 'mentions_count' => 0]);
        if (!Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
            return response()->json(['success' => true, 'mentions_count' => 0]);
        }
        $count = DB::table('t_wa_conversation_labels as cl')
            ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
            ->where('l.user_id', $me->id)
            ->whereNull('cl.mention_seen_at')
            ->count();
        return response()->json(['success' => true, 'mentions_count' => (int) $count]);
    }

    public function getLabels(Request $request)
    {
        if (!Schema::hasTable('t_wa_labels')) {
            return response()->json(['success' => true, 'labels' => [], 'can_manage' => false]);
        }
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $labels = \App\Models\WhatsApp\LabelModel::orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')->get()
            ->map(fn($l) => [
                'id' => $l->id, 'name' => $l->name, 'color' => $l->color,
                'user_id' => $l->user_id, 'is_system' => (bool) $l->is_system,
            ]);

        $canManage = auth()->user() && method_exists(auth()->user(), 'hasMobilePermission')
            ? auth()->user()->hasMobilePermission('manage_whatsapp_labels')
            : false;

        return response()->json(['success' => true, 'labels' => $labels, 'can_manage' => $canManage]);
    }

    public function createLabel(Request $request)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $data = $request->validate([
            'name'    => 'required|string|max:60',
            'color'   => 'nullable|string|max:20',
            'user_id' => 'nullable|integer|exists:t_sys_user,id',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Name required'], 422);
        }
        $exists = \App\Models\WhatsApp\LabelModel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'A label with that name already exists.'], 422);
        }
        $label = \App\Models\WhatsApp\LabelModel::create([
            'name' => $name, 'color' => $data['color'] ?? '#6B7280',
            'user_id' => $data['user_id'] ?? null, 'is_system' => 0,
            'created_by' => auth()->id(),
        ]);
        return response()->json(['success' => true, 'label' => $label]);
    }

    public function updateLabel(Request $request, $id)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $label = \App\Models\WhatsApp\LabelModel::find($id);
        if (!$label) return response()->json(['success' => false, 'message' => 'Label not found'], 404);

        $data = $request->validate([
            'name'    => 'sometimes|required|string|max:60',
            'color'   => 'sometimes|nullable|string|max:20',
            'user_id' => 'sometimes|nullable|integer|exists:t_sys_user,id',
        ]);
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') return response()->json(['success' => false, 'message' => 'Name required'], 422);
            $clash = \App\Models\WhatsApp\LabelModel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('id', '!=', $id)->exists();
            if ($clash) return response()->json(['success' => false, 'message' => 'Another label already has this name.'], 422);
            $label->name = $name;
        }
        if (array_key_exists('color', $data))   $label->color = $data['color'] ?: '#6B7280';
        if (array_key_exists('user_id', $data)) $label->user_id = $data['user_id'];
        $label->save();
        return response()->json(['success' => true, 'label' => $label]);
    }

    public function deleteLabel(Request $request, $id)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $label = \App\Models\WhatsApp\LabelModel::find($id);
        if (!$label) return response()->json(['success' => false, 'message' => 'Label not found'], 404);
        $label->delete();
        return response()->json(['success' => true]);
    }

    public function applyLabel(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $data = $request->validate(['label_id' => 'required|integer|exists:t_wa_labels,id']);
        $conv = ConversationModel::find($conversationId);
        if (!$conv) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        // Was this label already applied? We push-notify only on first apply
        // so re-toggling doesn't spam the mentioned user.
        $existing = \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
            ->where('label_id', $data['label_id'])
            ->first();
        $isNewApply = ($existing === null);

        $hasSeenCol = Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
        $payload = ['applied_by' => auth()->id(), 'applied_at' => now()];
        if ($hasSeenCol) $payload['mention_seen_at'] = null;

        \App\Models\WhatsApp\ConversationLabelModel::updateOrCreate(
            ['conversation_id' => $conversationId, 'label_id' => $data['label_id']],
            $payload
        );

        // Fire FCM mention push (Phase 2) — only on first apply and not a
        // self-mention. Non-fatal on any failure.
        // 🔴⏳ Oct-2026: people on the tag-alert list (Taimur) are owned by the follow-up
        // engine (loud alert + tagger reminders); null for everyone else → today's push below.
        try {
            $me = auth()->user();
            $owned = app(\App\Services\WhatsApp\MentionFollowupService::class)
                ->onTagged((int) $conversationId, (int) $data['label_id'], (int) ($me->id ?? 0));
            $label = $owned === null ? \App\Models\WhatsApp\LabelModel::find($data['label_id']) : null;
            if ($owned === null && $isNewApply && $label && $label->user_id && (int) $label->user_id !== (int) ($me->id ?? 0)) {
                app(\App\Services\FirebaseService::class)->notifyWhatsAppMention(
                    (int) $label->user_id,
                    $me->fullname ?? ('User #' . ($me->id ?? 0)),
                    $conv->display_name ?: ($conv->wa_contact_name ?: $conv->wa_phone),
                    (int) $conversationId
                );
            }
        } catch (\Exception $e) {
            Log::debug('applyLabel: mention push failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
    }

    public function removeLabel(Request $request, $conversationId, $labelId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        // 🔴⏳ Oct-2026: close any tag follow-up BEFORE the pivot row disappears.
        app(\App\Services\WhatsApp\MentionFollowupService::class)->onUntagged((int) $conversationId, (int) $labelId);
        \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
            ->where('label_id', $labelId)->delete();
        return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
    }

    /**
     * 🔴⏳ Oct-2026 — web twin of API\WhatsAppController::mentionFollowupAction. The buttons on
     * the "⏳ Taimur hasn't opened this" strip. Same service, same rules.
     */
    public function mentionFollowupAction(Request $request, $id)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $data = $request->validate(['action' => 'required|string|in:wait10,called,stop,on_it']);
        try {
            $r = app(\App\Services\WhatsApp\MentionFollowupService::class)->act((int) $id, (int) auth()->id(), $data['action']);
        } catch (\Throwable $e) {
            Log::error('mentionFollowupAction (web) failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that — try again.'], 500);
        }
        $status = $r['ok'] ? 200 : (['forbidden' => 403, 'not_found' => 404, 'bad_action' => 422][$r['code']] ?? 409);
        return response()->json(['success' => $r['ok'], 'code' => $r['code'], 'message' => $r['message'], 'followup' => $r['followup']], $status);
    }

    /**
     * Fetch labels currently applied to a conversation in the same shape
     * used elsewhere in this controller's responses.
     */
    protected function labelsForConversation($conversationId): array
    {
        if (!Schema::hasTable('t_wa_labels') || !Schema::hasTable('t_wa_conversation_labels')) {
            return [];
        }
        return DB::table('t_wa_conversation_labels as cl')
            ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
            ->where('cl.conversation_id', $conversationId)
            ->orderBy('l.name')
            ->get(['l.id', 'l.name', 'l.color', 'l.user_id'])
            ->map(fn($r) => [
                'id'      => (int) $r->id,
                'name'    => $r->name,
                'color'   => $r->color,
                'user_id' => $r->user_id ? (int) $r->user_id : null,
            ])->all();
    }

    /**
     * Global unread badge. Counts "any conversation with any unread
     * inbound message for the current user". This is exposed for the
     * top-bar badge in the layout.
     */
    public function getUnreadCount()
    {
        // Jun-2026 — Heartbeat for the online-payment auto-matcher (web side).
        // The header badge polls this regularly, giving a reliable drumbeat to
        // drive the payment workers on hosts without a working scheduler cron.
        // Fire-and-forget after the response flushes; self-throttled to ~1/min.
        try {
            app(\App\Services\Payments\PaymentSignalAutoRunner::class)->fireFromRequest();
        } catch (\Throwable $e) {
            // never affect the unread-count response
        }
        // 🔴⏳ Oct-2026: same drumbeat for tag reminders (backup to the StackCP task).
        try {
            app(\App\Services\WhatsApp\MentionFollowupService::class)->fireFromRequest();
        } catch (\Throwable $e) {
            // never affect the unread-count response
        }

        if (!Schema::hasTable('t_wa_conversations')) {
            return response()->json(['success' => true, 'unread_count' => 0]);
        }

        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            // No view permission → badge is always 0.
            return response()->json(['success' => true, 'unread_count' => 0]);
        }

        $userId = auth()->id();
        if (!$userId || !Schema::hasTable('t_wa_conversation_reads')) {
            // Fallback: legacy summed column. Approximates "conversations
            // with unread" by counting rows where unread_count > 0.
            $count = ConversationModel::where('unread_count', '>', 0)->count();
            return response()->json(['success' => true, 'unread_count' => (int) $count]);
        }

        // Apr-2026: badge counts CONVERSATIONS-with-unread (not raw messages).
        // The Unread filter in the inbox shows distinct chats; counting raw
        // inbound messages here was inflating the badge well past the visible
        // chat count (e.g. badge "68" while Unread tab shows "2 chats" because
        // most messages live in old chats nobody ever replied to). Using
        // COUNT(DISTINCT m.conversation_id) keeps the badge in lockstep with
        // what the operator sees when they click the Messages menu item.
        $query = DB::table('t_wa_messages as m')
            ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                $j->on('r.conversation_id', '=', 'm.conversation_id')
                  ->where('r.user_id', '=', $userId);
            })
            ->where('m.direction', 'inbound')
                ->where('m.created_at', '>=', \App\Models\FIN\ConfigModel::get('wa_unread_floor_date', '2026-06-28'))
            ->where(function ($w) {
                $w->whereNull('r.last_read_at')
                  ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
            })
            ->whereRaw('NOT EXISTS (
                SELECT 1 FROM t_wa_messages m2
                WHERE m2.conversation_id = m.conversation_id
                  AND m2.direction = \'outbound\' AND m2.sent_by IS NOT NULL
                  AND m2.created_at > m.created_at
            )');

        // System-answered taps don't count as unread. See UnreadQuery.
        \App\Services\WhatsApp\UnreadQuery::exclude($query);

        // Super-reader global read marker — honoured so Taimur's reads zero
        // the badge for all users. Only active once the migration has run.
        if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $query->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                  ->where(function ($w) {
                      $w->whereNull('c.global_read_at')
                        ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                  });
        }

        // Limited users' badge only counts messages inside their visible window.
        if ($access['limited']) {
            $query->where('m.created_at', '>=', $access['cutoff']);
        }

        $count = (int) $query
            ->selectRaw('COUNT(DISTINCT m.conversation_id) as cnt')
            ->value('cnt');

        // Forced-unread conversations always count for at least 1 each
        // (one row per conversation in t_wa_conversation_reads), so the
        // existing max() reconciliation still does the right thing now
        // that we're counting conversations on both sides.
        if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
            $forcedCount = DB::table('t_wa_conversation_reads')
                ->where('user_id', $userId)
                ->whereNotNull('forced_unread_at')
                ->count();
            if ($forcedCount > 0) {
                $count = max($count, $forcedCount);
            }
        }

        return response()->json(['success' => true, 'unread_count' => $count]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Qurbani Messages tab – settings & rescan
    // ─────────────────────────────────────────────────────────────────

    public function getQurbaniSettings(QurbaniClassifier $classifier)
    {
        return response()->json([
            'success' => true,
            'settings' => $classifier->getSettings(),
        ]);
    }

    public function updateQurbaniSettings(Request $request, QurbaniClassifier $classifier)
    {
        $request->validate([
            'enabled'  => 'sometimes|boolean',
            'keywords' => 'sometimes',
            'lookback' => 'sometimes|integer|min:1|max:20',
            'year'     => 'sometimes|nullable|integer|min:2000|max:2100',
        ]);

        $settings = $classifier->updateSettings($request->only(['enabled', 'keywords', 'lookback', 'year']));
        return response()->json(['success' => true, 'settings' => $settings]);
    }

    public function rescanQurbani(QurbaniClassifier $classifier)
    {
        try {
            $stats = $classifier->rescanAll();
            return response()->json(['success' => true] + $stats);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Auto-Reply settings (out-of-hours / day-off / custom rules)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Permission gate for the auto-reply admin endpoints. We deliberately
     * 403 instead of 404 so the operator who clicked the gear button gets
     * a clear "you can't manage these" signal in DevTools — the gear is
     * already permission-hidden in the UI for the same role check.
     */
    protected function requireAutoReplyPermission()
    {
        $user = auth()->user();
        if (!$user || !method_exists($user, 'hasMobilePermission') || !$user->hasMobilePermission('manage_wa_auto_reply')) {
            abort(403, 'You do not have permission to manage auto-reply settings.');
        }
    }

    /**
     * GET /messages/auto-reply
     *
     * Returns the master toggle plus the full ordered rule list. Used to
     * hydrate the Auto-Reply settings modal. Anyone with manage permission
     * can read; the regular WhatsApp inbox does not need this payload.
     */
    public function getAutoReplySettings()
    {
        $this->requireAutoReplyPermission();

        if (!Schema::hasTable('t_wa_auto_replies')) {
            return response()->json([
                'success'  => false,
                'message'  => 'Auto-reply migration has not been applied. Run database/migrations/add_wa_auto_reply_apr2026.sql.',
            ], 503);
        }

        $enabled = (new WhatsAppService())->isAutoReplyEnabled();
        $rules = DB::table('t_wa_auto_replies')
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($r) {
                return [
                    'id'             => (int) $r->id,
                    'name'           => $r->name,
                    'message'        => $r->message,
                    'enabled'        => (int) $r->enabled === 1,
                    'priority'       => (int) $r->priority,
                    'match_mode'     => $r->match_mode,
                    'days_of_week'   => $r->days_of_week,
                    'start_time'     => $r->start_time,
                    'end_time'       => $r->end_time,
                    'specific_dates' => $r->specific_dates ? (json_decode($r->specific_dates, true) ?: []) : [],
                    'cooldown_hours' => (int) $r->cooldown_hours,
                    // Jun-2026 cooldown/active-window fix. `?? null` tolerates a
                    // pre-migration schema (columns absent → sane defaults).
                    'cooldown_mode'  => $r->cooldown_mode ?? 'daily',
                    'active_from'    => $r->active_from ?? null,
                    'active_to'      => $r->active_to ?? null,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'enabled' => $enabled,
            'rules'   => $rules,
        ]);
    }

    /**
     * POST /messages/auto-reply/toggle
     *
     * Flip the master kill-switch. Kept as a separate endpoint from the
     * rule CRUD because the toggle is the most-tapped action and we don't
     * want the operator to have to load + re-save the entire rule list
     * just to silence auto-replies during an event.
     */
    public function toggleAutoReply(Request $request)
    {
        $this->requireAutoReplyPermission();
        $request->validate(['enabled' => 'required|boolean']);

        DB::table('t_fin_config')->updateOrInsert(
            ['config_key' => 'wa_auto_reply_enabled'],
            ['config_value' => $request->boolean('enabled') ? '1' : '0', 'updated_at' => now()]
        );

        return response()->json([
            'success' => true,
            'enabled' => $request->boolean('enabled'),
        ]);
    }

    /**
     * POST /messages/auto-reply/rules        (create)
     * POST /messages/auto-reply/rules/{id}   (update)
     *
     * Both routes share this handler — the existence of {id} drives the
     * insert-vs-update branch. Validation happens up-front so we never
     * write a half-baked row that maybeSendAutoReply would then refuse
     * to match (see autoReplyRuleMatches, which fails closed on partial
     * time_window configurations).
     */
    public function saveAutoReplyRule(Request $request, $id = null)
    {
        $this->requireAutoReplyPermission();

        $request->validate([
            'name'           => 'required|string|max:150',
            'message'        => 'required|string|max:4000',
            'enabled'        => 'sometimes|boolean',
            'priority'       => 'sometimes|integer|min:0|max:9999',
            'match_mode'     => 'required|in:time_window,specific_dates,always',
            'days_of_week'   => 'nullable|string|max:50',
            'start_time'     => 'nullable|date_format:H:i',
            'end_time'       => 'nullable|date_format:H:i',
            'specific_dates' => 'nullable|array',
            'specific_dates.*' => 'date_format:Y-m-d',
            'cooldown_hours' => 'sometimes|integer|min:0|max:168',
            'cooldown_mode'  => 'sometimes|in:rolling,daily,once',
            'active_from'    => 'nullable|date_format:Y-m-d',
            'active_to'      => 'nullable|date_format:Y-m-d',
        ]);

        // Normalize days_of_week: drop anything that isn't 0..6, dedupe,
        // sort. The renderer on the client tolerates any order but the DB
        // value is easier to debug when canonical.
        $days = '';
        if ($request->filled('days_of_week')) {
            $bits = array_unique(array_filter(array_map('intval', explode(',', (string) $request->input('days_of_week'))), function ($n) {
                return $n >= 0 && $n <= 6;
            }));
            sort($bits);
            $days = implode(',', $bits);
        }

        // start/end_time arrive as HH:MM from the time picker; the column
        // expects HH:MM:SS. Pad here to keep the DB representation stable.
        $start = $request->filled('start_time') ? $request->input('start_time') . ':00' : null;
        $end   = $request->filled('end_time')   ? $request->input('end_time')   . ':00' : null;

        $payload = [
            'name'           => $request->input('name'),
            'message'        => $request->input('message'),
            'enabled'        => $request->boolean('enabled', true) ? 1 : 0,
            'priority'       => (int) $request->input('priority', 100),
            'match_mode'     => $request->input('match_mode'),
            'days_of_week'   => $days ?: null,
            'start_time'     => $start,
            'end_time'       => $end,
            'specific_dates' => $request->filled('specific_dates') ? json_encode(array_values($request->input('specific_dates'))) : null,
            'cooldown_hours' => (int) $request->input('cooldown_hours', 6),
            'updated_at'     => now(),
        ];

        // Jun-2026 cooldown/active-window columns. Added conditionally so an
        // upload-before-SQL can't 500 the save — the keys are only written when
        // the columns exist (after add_wa_auto_reply_cooldown_fix_jun2026.sql).
        if (Schema::hasColumn('t_wa_auto_replies', 'cooldown_mode')) {
            $payload['cooldown_mode'] = $request->input('cooldown_mode', 'daily');
        }
        if (Schema::hasColumn('t_wa_auto_replies', 'active_from')) {
            $payload['active_from'] = $request->filled('active_from') ? $request->input('active_from') : null;
            $payload['active_to']   = $request->filled('active_to') ? $request->input('active_to') : null;
        }

        if ($id) {
            $affected = DB::table('t_wa_auto_replies')->where('id', $id)->update($payload);
            if (!$affected && !DB::table('t_wa_auto_replies')->where('id', $id)->exists()) {
                return response()->json(['success' => false, 'message' => 'Rule not found.'], 404);
            }
            return response()->json(['success' => true, 'id' => (int) $id]);
        }

        $payload['created_at'] = now();
        $newId = DB::table('t_wa_auto_replies')->insertGetId($payload);
        return response()->json(['success' => true, 'id' => (int) $newId]);
    }

    /**
     * DELETE /messages/auto-reply/rules/{id}
     *
     * Hard delete (no soft-delete column on this table). Existing outbound
     * t_wa_messages.auto_reply_rule_id rows pointing at this id are kept
     * intact — they remain valid as a historical "this message was an
     * auto-reply" stamp even after the rule itself is gone.
     */
    public function deleteAutoReplyRule($id)
    {
        $this->requireAutoReplyPermission();
        DB::table('t_wa_auto_replies')->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    public function getTemplates(Request $request)
    {
        if (!Schema::hasTable('t_wa_templates')) {
            return response()->json(['success' => true, 'templates' => []]);
        }
        $query = \App\Models\WhatsApp\TemplateModel::where('status', 'approved');

        // Hide inactive templates from every picker (soft-disable). Settings
        // page passes include_inactive=1 so the manager can still see/edit them.
        $includeInactive = $request->boolean('include_inactive', false);
        if (!$includeInactive && Schema::hasColumn('t_wa_templates', 'is_active')) {
            $query->where('is_active', 1);
        }

        $context = $request->query('context');

        // Automation-only templates (show_in = 'automation') exist purely to be
        // fired by a rule — order_received_*, deliver_*, invoice_paychange,
        // monsoon, ... A picker that names its contexts already excludes them
        // (FIND_IN_SET never matches). But a picker that asks with NO context
        // gets EVERYTHING, which is how ~11 automation templates ended up in the
        // manual "Send Template" list and the mobile Campaigns picker —
        // confusing, since sending one by hand is never the intent.
        //
        // So: no context => hide automation-ONLY templates unless the caller
        // explicitly opts in with include_automation=1. Two callers do (the
        // automation rule picker and Manage Templates, which shows them in its
        // own collapsed 🤖 section). Templates tagged 'automation,messages' are
        // deliberately dual-purpose and are NOT hidden.
        if (!$context && !$request->boolean('include_automation', false)) {
            $query->whereRaw("TRIM(COALESCE(show_in, '')) <> 'automation'");
        }

        if ($context) {
            $contexts = array_map('trim', explode(',', $context));
            $query->where(function ($q) use ($contexts) {
                foreach ($contexts as $ctx) {
                    if ($ctx === '') continue;
                    $q->orWhereRaw("FIND_IN_SET(?, show_in) > 0", [$ctx]);
                }
            });

            // Scope filtering based on whether this picker is a Qurbani picker
            // or a regular picker. Qurbani pages explicitly request contexts
            // that start with "qurbani_" (or equal "qurbani").
            //   - Non-qurbani picker → hide qurbani-only templates
            //   - Qurbani picker     → hide regular-only templates
            // Templates with both flags = 0 are "Common" and appear everywhere.
            $isQurbaniCtx = false;
            foreach ($contexts as $c) {
                if ($c !== '' && (str_starts_with($c, 'qurbani_') || $c === 'qurbani')) {
                    $isQurbaniCtx = true;
                    break;
                }
            }
            if (!$isQurbaniCtx && Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) {
                $query->where('is_qurbani_only', 0);
            }
            if ($isQurbaniCtx && Schema::hasColumn('t_wa_templates', 'is_regular_only')) {
                $query->where('is_regular_only', 0);
            }
        }

        $templates = $query->orderBy('display_name')->get();
        return response()->json(['success' => true, 'templates' => $templates]);
    }

    public function storeTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'display_name' => 'required|string|max:255',
            'body_text' => 'required|string',
            'category' => 'nullable|in:utility,marketing,authentication',
            'variable_count' => 'nullable|integer|min:0|max:10',
            'has_buttons' => 'nullable|boolean',
            'button_labels' => 'nullable|array',
            'header_text' => 'nullable|string|max:255',
            'show_in' => 'nullable|string|max:100',
            'footer_text' => 'nullable|string|max:60',
        ]);

        $isDefault = $request->boolean('is_default', false);
        $showIn = $request->show_in ?? 'messages,orders,customers,shopify';
        $isActive = $request->boolean('is_active', true);
        $isQurbaniOnly = $request->boolean('is_qurbani_only', false);
        $isRegularOnly = $request->boolean('is_regular_only', false);
        // Mutually exclusive: if both are sent as 1 (shouldn't happen with the
        // radio UI, but be defensive), prefer qurbani-only and clear regular-only.
        if ($isQurbaniOnly && $isRegularOnly) {
            $isRegularOnly = false;
        }

        if ($isDefault) {
            $this->clearDefaultsForContexts($showIn);
        }

        $attrs = [
            'name' => $request->name,
            'display_name' => $request->display_name,
            'body_text' => $request->body_text,
            'category' => $request->category ?? 'utility',
            'language' => 'en',
            'status' => 'approved',
            'variable_count' => $request->variable_count ?? 0,
            'has_buttons' => $request->has_buttons ?? false,
            'button_labels' => $request->button_labels ? json_encode($request->button_labels) : null,
            'header_text' => $request->header_text,
            'footer_text' => $request->footer_text,
            'show_in' => $showIn,
            'is_default' => $isDefault,
        ];
        if (Schema::hasColumn('t_wa_templates', 'is_active')) $attrs['is_active'] = $isActive;
        if (Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) $attrs['is_qurbani_only'] = $isQurbaniOnly;
        if (Schema::hasColumn('t_wa_templates', 'is_regular_only')) $attrs['is_regular_only'] = $isRegularOnly;

        $template = \App\Models\WhatsApp\TemplateModel::create($attrs);

        return response()->json(['success' => true, 'template' => $template]);
    }

    public function updateTemplate(Request $request, $id)
    {
        $template = \App\Models\WhatsApp\TemplateModel::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'display_name' => 'sometimes|required|string|max:255',
            'body_text' => 'sometimes|required|string',
            'category' => 'sometimes|nullable|in:utility,marketing,authentication',
            'variable_count' => 'sometimes|nullable|integer|min:0|max:10',
            'has_buttons' => 'sometimes|nullable|boolean',
            'button_labels' => 'sometimes|nullable|array',
            'header_text' => 'sometimes|nullable|string|max:255',
            'footer_text' => 'sometimes|nullable|string|max:60',
            'show_in' => 'sometimes|required|string|max:100',
        ]);

        $isDefault = $request->has('is_default')
            ? $request->boolean('is_default')
            : (bool) $template->is_default;
        $showIn = $request->has('show_in') ? $request->show_in : $template->show_in;

        if ($isDefault) {
            $this->clearDefaultsForContexts($showIn, $template->id);
        }

        if ($request->has('name')) $template->name = $request->name;
        if ($request->has('display_name')) $template->display_name = $request->display_name;
        if ($request->has('body_text')) $template->body_text = $request->body_text;
        if ($request->has('category')) $template->category = $request->category ?? 'utility';
        if ($request->has('variable_count')) $template->variable_count = (int) ($request->variable_count ?? 0);
        if ($request->has('has_buttons')) $template->has_buttons = (bool) $request->has_buttons;
        if ($request->has('button_labels')) {
            $template->button_labels = $request->button_labels
                ? json_encode($request->button_labels)
                : null;
        }
        if ($request->has('header_text')) $template->header_text = $request->header_text;
        if ($request->has('footer_text')) $template->footer_text = $request->footer_text;

        $template->show_in = $showIn;
        $template->is_default = $isDefault;

        if (Schema::hasColumn('t_wa_templates', 'is_active') && $request->has('is_active')) {
            $template->is_active = $request->boolean('is_active');
        }
        if (Schema::hasColumn('t_wa_templates', 'is_qurbani_only') && $request->has('is_qurbani_only')) {
            $template->is_qurbani_only = $request->boolean('is_qurbani_only');
        }
        if (Schema::hasColumn('t_wa_templates', 'is_regular_only') && $request->has('is_regular_only')) {
            $template->is_regular_only = $request->boolean('is_regular_only');
        }
        // Mutually exclusive flags: clearing one implicit when the other is set to 1.
        if ((bool) $template->is_qurbani_only && (bool) ($template->is_regular_only ?? false)) {
            // Prefer the one the user just toggled on. If only one of them was in
            // the request, trust it; otherwise keep qurbani_only and clear regular_only.
            if ($request->has('is_regular_only') && !$request->has('is_qurbani_only')) {
                $template->is_qurbani_only = false;
            } else {
                $template->is_regular_only = false;
            }
        }

        $template->save();
        return response()->json(['success' => true, 'template' => $template]);
    }

    public function deleteTemplate($id)
    {
        $template = \App\Models\WhatsApp\TemplateModel::findOrFail($id);
        $template->delete();
        return response()->json(['success' => true]);
    }

    private function clearDefaultsForContexts(string $showIn, ?int $excludeId = null)
    {
        $contexts = array_map('trim', explode(',', $showIn));
        $invoiceContexts = array_intersect($contexts, ['invoice', 'qurbani_invoice']);
        if (empty($invoiceContexts)) return;

        $query = \App\Models\WhatsApp\TemplateModel::where('is_default', true);
        if ($excludeId) $query->where('id', '!=', $excludeId);
        $others = $query->get();

        foreach ($others as $other) {
            $otherContexts = array_map('trim', explode(',', $other->show_in));
            if (!empty(array_intersect($otherContexts, $invoiceContexts))) {
                $other->is_default = false;
                $other->save();
            }
        }
    }

    /**
     * Get orders for a customer (for invoice picker)
     */
    public function getCustomerOrders(Request $request, $customerId)
    {
        // Phase 3 (May-2026): we now need the per-line-item qurbani
        // metadata so the right-rail can render a "🕒 Timeline" button
        // for QUR orders (which can have multiple bundles, hence
        // line-item-level granularity). The eager-loaded lineItems
        // selection is widened to include the qurbani_* fields used
        // by the timeline tab switcher.
        //
        // Phase 4 (May-2026): we also surface an "active_delivery"
        // top-level summary used by the chat-header banner on the
        // messages page so customer-service can see where the
        // customer's currently-OFD order sits in the rider's route
        // (ETA + how many stops are ahead) without leaving the chat.
        // Works for BOTH qurbani bundles and regular orders. Computed
        // here on the same fetch so we don't add a new HTTP poll —
        // the existing loadOrdersPanel() / convPollTimer reuses this
        // endpoint.
        $orders = OrderModel::where('customer_id', $customerId)
            ->with([
                'lineItems:id,order_id,name,quantity,unit_price,line_total,qurbani_day,qurbani_slot,qurbani_region,qurbani_delivery_type,qurbani_item_status,qurbani_assigned_rider_user_id,qurbani_dispatched_at,qurbani_delivered_at,qurbani_delivery_priority,qurbani_estimated_delivery_at,qurbani_eta_calculated_at',
                'assignedRider:id,fullname',
            ])
            ->orderBy('order_date', 'desc')
            ->limit(20)
            ->get(['id', 'order_number', 'order_date', 'order_status', 'total_price', 'customer_id', 'assigned_rider_user_id', 'estimated_delivery_at', 'eta_calculated_at', 'delivery_priority', 'payment_method']);

        // Cash/online + payment-proof badges for the right-rail order cards.
        // RECORD surface → suppressSettled:false so an approved online order
        // keeps its "proof received / verified" badge (a permanent marker, not
        // an action prompt like the inbox list).
        $proofMap = [];
        if (config('payment_signals.enabled')) {
            $proofIds = $orders->pluck('id')->all();
            if (!empty($proofIds)) {
                $proofMap = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                    ->forOrders($proofIds, suppressSettled: false);
            }
        }

        // Pre-compute "ahead count" lookups in batches so we don't
        // do N+1 queries. We collect (rider_id, current_priority) for
        // each candidate OFD entity and run a single grouped query
        // for each bucket (regular orders / qurbani line items).
        $regularOfdIds = [];
        $qurbaniOfdLineItems = []; // line_item_id => [rider_id, priority]
        foreach ($orders as $o) {
            if (strtolower((string) $o->order_status) === 'out_for_delivery'
                && $o->assigned_rider_user_id
                && $o->delivery_priority !== null) {
                $regularOfdIds[] = (int) $o->id;
            }
            foreach ($o->lineItems as $li) {
                if ($li->qurbani_dispatched_at
                    && !$li->qurbani_delivered_at
                    && $li->qurbani_assigned_rider_user_id
                    && $li->qurbani_delivery_priority !== null
                    && strtolower((string) ($li->qurbani_item_status ?? '')) !== 'cancelled') {
                    $qurbaniOfdLineItems[(int) $li->id] = [
                        'rider_id' => (int) $li->qurbani_assigned_rider_user_id,
                        'priority' => (int) $li->qurbani_delivery_priority,
                    ];
                }
            }
        }

        // Build the ahead-count map for regular orders. We fetch the
        // route once per rider so we can compute both the ahead count
        // and the total-remaining (sibling stops still pending) for
        // each focal order in O(1) per order.
        $regularAheadMap = []; // [order_id => [ahead, total_remaining]]
        if (!empty($regularOfdIds)) {
            $focal = OrderModel::whereIn('id', $regularOfdIds)
                ->get(['id', 'assigned_rider_user_id', 'delivery_priority']);
            $ridersToLoad = $focal->pluck('assigned_rider_user_id')->filter()->unique()->all();
            $routesByRider = [];
            if (!empty($ridersToLoad)) {
                $routesByRider = OrderModel::whereIn('assigned_rider_user_id', $ridersToLoad)
                    ->where('order_status', 'out_for_delivery')
                    ->whereNotNull('delivery_priority')
                    ->get(['id', 'assigned_rider_user_id', 'delivery_priority'])
                    ->groupBy('assigned_rider_user_id');
            }
            foreach ($focal as $fo) {
                $route = $routesByRider[$fo->assigned_rider_user_id] ?? collect();
                $ahead = $route->filter(function ($r) use ($fo) {
                    return $r->delivery_priority < $fo->delivery_priority && $r->id !== $fo->id;
                })->count();
                $regularAheadMap[(int) $fo->id] = [
                    'ahead_count'     => (int) $ahead,
                    'total_remaining' => (int) $route->count(),
                ];
            }
        }

        // Same logic for qurbani line items — siblings on the same
        // rider that are dispatched but not yet delivered.
        $qurbaniAheadMap = []; // [line_item_id => [ahead, total_remaining]]
        if (!empty($qurbaniOfdLineItems)) {
            $ridersToLoadQ = array_unique(array_column($qurbaniOfdLineItems, 'rider_id'));
            $qurbaniRoutesByRider = [];
            if (!empty($ridersToLoadQ)) {
                $qurbaniRoutesByRider = \DB::table('t_crm_prod_order_line_item')
                    ->whereIn('qurbani_assigned_rider_user_id', $ridersToLoadQ)
                    ->whereNotNull('qurbani_dispatched_at')
                    ->whereNull('qurbani_delivered_at')
                    ->whereNotNull('qurbani_delivery_priority')
                    ->select('id', 'qurbani_assigned_rider_user_id', 'qurbani_delivery_priority')
                    ->get()
                    ->groupBy('qurbani_assigned_rider_user_id');
            }
            foreach ($qurbaniOfdLineItems as $liId => $info) {
                $route = $qurbaniRoutesByRider[$info['rider_id']] ?? collect();
                $ahead = $route->filter(function ($r) use ($info, $liId) {
                    return (int) $r->qurbani_delivery_priority < $info['priority']
                        && (int) $r->id !== $liId;
                })->count();
                $qurbaniAheadMap[$liId] = [
                    'ahead_count'     => (int) $ahead,
                    'total_remaining' => (int) $route->count(),
                ];
            }
        }

        $activeDelivery = null; // sorted by soonest ETA across all OFD entities
        $activeCandidates = [];

        $mapped = $orders->map(function($o) use ($regularAheadMap, $qurbaniAheadMap, $proofMap, &$activeCandidates) {
            $eta = null;
            $etaIso = null;
            if ($o->estimated_delivery_at && strtolower((string) $o->order_status) === 'out_for_delivery') {
                try {
                    $eta = \Carbon\Carbon::parse($o->estimated_delivery_at)->format('h:i A');
                    $etaIso = (string) $o->estimated_delivery_at;
                } catch (\Exception $e) {}
            }

            // QUR orders are detected by either their order_number
            // prefix (Qurbani orders typically start with QUR) OR
            // by ANY line item carrying qurbani_* metadata. The
            // second test catches manual-entry orders that may not
            // follow the QUR-prefix convention.
            $orderNum = (string) ($o->order_number ?? '');
            $hasQurbaniMeta = $o->lineItems->contains(function ($li) {
                return !empty($li->qurbani_day) || !empty($li->qurbani_slot)
                    || !empty($li->qurbani_region) || !empty($li->qurbani_delivery_type);
            });
            $isQurbani = (str_starts_with(strtoupper($orderNum), 'QUR') || $hasQurbaniMeta);

            // Build qurbani_items only for qurbani orders to keep
            // the payload small for regular orders. Each item
            // carries just enough info to power the timeline-tab
            // labels client-side.
            $qurbaniItems = [];
            if ($isQurbani) {
                foreach ($o->lineItems as $li) {
                    if (empty($li->qurbani_day) && empty($li->qurbani_slot)
                        && empty($li->qurbani_region) && empty($li->qurbani_delivery_type)) {
                        continue;
                    }
                    $qurbaniItems[] = [
                        'line_item_id' => (int) $li->id,
                        'name'         => (string) $li->name,
                        'qurbani_day'  => $li->qurbani_day,
                        'qurbani_slot' => $li->qurbani_slot,
                        'qurbani_region' => $li->qurbani_region,
                        'qurbani_delivery_type' => $li->qurbani_delivery_type,
                        'status'       => $li->qurbani_item_status,
                    ];
                }

                // Each qurbani line item that's currently OFD becomes
                // a candidate for the chat-header banner.
                foreach ($o->lineItems as $li) {
                    if (!isset($qurbaniAheadMap[(int) $li->id])) continue;
                    $liEta = $li->qurbani_estimated_delivery_at;
                    $liEtaHuman = null;
                    $liEtaTs = null;
                    if ($liEta) {
                        try {
                            $liEtaHuman = \Carbon\Carbon::parse($liEta)->format('h:i A');
                            $liEtaTs = \Carbon\Carbon::parse($liEta)->getTimestamp();
                        } catch (\Exception $e) {}
                    }
                    // May-2026 — pass eta_calculated_at through so the
                    // banner can show "calc'd 3m ago" / "calc'd 47m
                    // ago" and the CS manager can spot a stale-display
                    // problem without auto-refresh kicking in.
                    $etaCalcIso = $li->qurbani_eta_calculated_at
                        ? (string) $li->qurbani_eta_calculated_at : null;
                    $etaAgeMin = null;
                    if ($etaCalcIso) {
                        try {
                            $etaAgeMin = (int) \Carbon\Carbon::parse($etaCalcIso)->diffInMinutes(now(), false);
                            if ($etaAgeMin < 0) $etaAgeMin = 0;
                        } catch (\Throwable $e) { $etaAgeMin = null; }
                    }
                    $activeCandidates[] = [
                        'sort_ts'         => $liEtaTs ?: PHP_INT_MAX,
                        'type'            => 'qurbani',
                        'order_id'        => (int) $o->id,
                        'order_number'    => $o->order_number,
                        'line_item_id'    => (int) $li->id,
                        'line_item_name'  => (string) $li->name,
                        'rider_name'      => $o->assignedRider?->fullname,
                        'eta_at'          => $liEta ? (string) $liEta : null,
                        'eta_human'       => $liEtaHuman,
                        'eta_calculated_at' => $etaCalcIso,
                        'eta_age_minutes' => $etaAgeMin,
                        'ahead_count'     => $qurbaniAheadMap[(int) $li->id]['ahead_count'],
                        'total_remaining' => $qurbaniAheadMap[(int) $li->id]['total_remaining'],
                        'qurbani_day'     => $li->qurbani_day,
                        'qurbani_slot'    => $li->qurbani_slot,
                        'qurbani_delivery_type' => $li->qurbani_delivery_type,
                    ];
                }
            }

            // Regular OFD orders also go into the candidate pool.
            $ofdPosition = null;
            if (isset($regularAheadMap[(int) $o->id])) {
                $ofdPosition = [
                    'ahead_count'     => $regularAheadMap[(int) $o->id]['ahead_count'],
                    'total_remaining' => $regularAheadMap[(int) $o->id]['total_remaining'],
                ];
                $activeCandidates[] = [
                    'sort_ts'         => $etaIso
                        ? \Carbon\Carbon::parse($etaIso)->getTimestamp()
                        : PHP_INT_MAX,
                    'type'            => 'regular',
                    'order_id'        => (int) $o->id,
                    'order_number'    => $o->order_number,
                    'line_item_id'    => null,
                    'line_item_name'  => null,
                    'rider_name'      => $o->assignedRider?->fullname,
                    'eta_at'          => $etaIso,
                    'eta_human'       => $eta,
                    'ahead_count'     => $regularAheadMap[(int) $o->id]['ahead_count'],
                    'total_remaining' => $regularAheadMap[(int) $o->id]['total_remaining'],
                ];
            }

            return [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'order_date' => $o->order_date,
                'status' => $o->order_status,
                'total' => $o->total_price,
                'items_count' => $o->lineItems->count(),
                'items_summary' => $o->lineItems->take(3)->pluck('name')->join(', '),
                'rider_name' => $o->assignedRider?->fullname,
                'eta' => $eta,
                'is_qurbani' => $isQurbani,
                'qurbani_items' => $qurbaniItems,
                'ofd_position' => $ofdPosition,
                'payment_method' => OrderModel::paymentChannel($o->payment_method),
                'payment_proof' => $proofMap[$o->id] ?? null,
            ];
        });

        // ── ETA-drift enrichment (May-2026 — CS staleness guard) ───
        // The active-delivery banner now also tells the CS manager
        // whether the customer is sitting on a stale ETA from a
        // previous WhatsApp. The signal is exactly the same one the
        // Performance dashboard and the manager's planner use:
        //   current ETA  = li.qurbani_estimated_delivery_at
        //   messaged ETA = latest t_ops_qurbani_wa_log row with
        //                  trigger_event IN ('ofd','ofd_delay_update')
        //                  and status = 'sent' for that line item
        // If |current - messaged| > threshold the chip turns red and
        // CS knows to fire an updated WA before quoting the new ETA.
        //
        // Batched lookup so we never N+1 (max 20 orders * 5 line items
        // wouldn't bite, but the pattern is the right one).
        $qurbaniCandidateLiIds = [];
        foreach ($activeCandidates as $cand) {
            if (($cand['type'] ?? '') === 'qurbani' && !empty($cand['line_item_id'])) {
                $qurbaniCandidateLiIds[] = (int) $cand['line_item_id'];
            }
        }
        $waSentMap = []; // line_item_id => ['messaged_eta_at', 'last_wa_sent_at', 'last_wa_trigger']
        if (!empty($qurbaniCandidateLiIds) && \Illuminate\Support\Facades\Schema::hasTable('t_ops_qurbani_wa_log')) {
            // Latest sent OFD-ish row per line item, in one query.
            $sub = \DB::table('t_ops_qurbani_wa_log')
                ->select('line_item_id', \DB::raw('MAX(created_at) as mx'))
                ->whereIn('line_item_id', $qurbaniCandidateLiIds)
                ->whereIn('trigger_event', ['ofd', 'ofd_delay_update'])
                ->where('status', 'sent')
                ->groupBy('line_item_id');
            $waRows = \DB::table('t_ops_qurbani_wa_log as l')
                ->joinSub($sub, 'mx', function ($j) {
                    $j->on('mx.line_item_id', '=', 'l.line_item_id')
                      ->on('mx.mx', '=', 'l.created_at');
                })
                ->whereIn('l.trigger_event', ['ofd', 'ofd_delay_update'])
                ->where('l.status', 'sent')
                ->select('l.line_item_id', 'l.delivery_time_used', 'l.created_at', 'l.trigger_event')
                ->get();
            foreach ($waRows as $row) {
                $waSentMap[(int) $row->line_item_id] = [
                    'messaged_eta_at' => $row->delivery_time_used ? (string) $row->delivery_time_used : null,
                    'last_wa_sent_at' => $row->created_at ? (string) $row->created_at : null,
                    'last_wa_trigger' => (string) $row->trigger_event,
                ];
            }
        }
        // Drift threshold mirrors the auto-sender so the "needs update"
        // signal here matches the planner's delay-update detection.
        $driftThreshold = (int) \App\Models\FIN\ConfigModel::get('qurbani_wa_ofd_delay_threshold_minutes', '30');
        if ($driftThreshold < 1) $driftThreshold = 30;

        foreach ($activeCandidates as &$cand) {
            $cand['messaged_eta_at']   = null;
            $cand['last_wa_sent_at']   = null;
            $cand['last_wa_trigger']   = null;
            $cand['eta_drift_minutes'] = null;
            $cand['eta_drift_state']   = 'none';
            $cand['drift_threshold_minutes'] = $driftThreshold;
            if (($cand['type'] ?? '') !== 'qurbani') continue;
            $liId = (int) ($cand['line_item_id'] ?? 0);
            if (!$liId || !isset($waSentMap[$liId])) continue;
            $info = $waSentMap[$liId];
            $cand['messaged_eta_at'] = $info['messaged_eta_at'];
            $cand['last_wa_sent_at'] = $info['last_wa_sent_at'];
            $cand['last_wa_trigger'] = $info['last_wa_trigger'];
            if ($info['messaged_eta_at'] && !empty($cand['eta_at'])) {
                try {
                    $msg = \Carbon\Carbon::parse($info['messaged_eta_at']);
                    $cur = \Carbon\Carbon::parse($cand['eta_at']);
                    $delta = (int) $msg->diffInMinutes($cur, false);
                    $cand['eta_drift_minutes'] = $delta;
                    $abs = abs($delta);
                    if ($abs <= 5)                  $cand['eta_drift_state'] = 'in_sync';
                    elseif ($abs <= $driftThreshold) $cand['eta_drift_state'] = 'drifting';
                    else                             $cand['eta_drift_state'] = 'stale';
                } catch (\Throwable $e) { /* leave drift_state='none' */ }
            }
        }
        unset($cand);

        // Pick the soonest-ETA candidate as the banner's primary
        // entry. If multiple, the others come along as `more_count`
        // so the banner can hint that the customer has more in flight.
        if (!empty($activeCandidates)) {
            usort($activeCandidates, function ($a, $b) {
                return $a['sort_ts'] <=> $b['sort_ts'];
            });
            $primary = $activeCandidates[0];
            unset($primary['sort_ts']);
            $primary['more_count'] = max(0, count($activeCandidates) - 1);
            $activeDelivery = $primary;
        }

        return response()->json([
            'success' => true,
            'orders' => $mapped,
            'active_delivery' => $activeDelivery,
        ]);
    }

    /**
     * Get invoice image URL — ALWAYS forces fresh capture.
     *
     * May-2026: removed the on-disk cache lookup. Returning a cached image
     * caused two problems:
     *   (1) Shopify orders sharing the same `order_number` (different DB
     *       order_ids) collide on the same filename, so previewing one
     *       order would surface the other one's image.
     *   (2) Edits to an existing order (line items, totals, notes) didn't
     *       always trigger a cache-bust if the edit went through a flow
     *       other than OrderController::update(), so users saw the
     *       pre-edit invoice image even though body params (totals, etc.)
     *       were correct.
     *
     * Every preview now re-captures and overwrites the file on disk. The
     * Send flow (sendInvoice / mobile auto-attach) reads that just-written
     * file via readInvoiceImageUrlForSend() and tacks an mtime query
     * string on the URL so Meta sees a unique URL each send and never
     * re-uses its own cached copy.
     *
     * Mobile clients (no html2canvas) can still call this endpoint, but
     * they'll always get needs_capture=true. The mobile picker is
     * intentionally pointed at readInvoiceImageUrlForSend() instead so it
     * surfaces whatever the last web capture wrote — same pre-existing
     * behaviour, no regression.
     */
    public function getInvoiceImageUrl(Request $request, $orderId)
    {
        try {
            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($orderId);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));

            return response()->json([
                'success' => true,
                'needs_capture' => true,
                // Aug-2026: ?wa=1 switches the invoice blade to the compact
                // WhatsApp-capture layout (560px sheet, larger type) so the
                // sent image reads fitted on a phone. Only this capture URL
                // carries the flag - print/PDF/customer app are untouched.
                'invoice_url' => url('/orders/' . $orderId . '/invoice') . '?wa=1',
                'order_number' => $orderNum,
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            Log::error('Invoice image generation failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Locate the captured invoice image for an order on the public disk.
     *
     * Captures are stored as Invoice-{orderNum}.jpg (Jul-2026 onward — the
     * web pages now capture as smaller JPEGs) or Invoice-{orderNum}.png
     * (older captures + any page still uploading PNG). At most one of the
     * two exists: uploadInvoiceImage deletes the sibling extension on every
     * save, and the order-edit cache clear removes both — so a stale
     * counterpart can never shadow a fresh capture.
     *
     * Returns [storagePath, mime] or [null, null] when nothing is captured.
     */
    private function findInvoiceImage(string $orderNum): array
    {
        $base = 'whatsapp-invoices/Invoice-' . $orderNum;
        if (Storage::disk('public')->exists($base . '.jpg')) {
            return [$base . '.jpg', 'image/jpeg'];
        }
        if (Storage::disk('public')->exists($base . '.png')) {
            return [$base . '.png', 'image/png'];
        }
        return [null, null];
    }

    /**
     * Read an already-captured invoice image's URL, with an mtime cache
     * buster so both browsers and Meta see a unique URL whenever the file
     * is overwritten by a fresh capture. Returns ['success' => false]
     * when the file hasn't been generated yet (mobile-without-prior-web-
     * capture case).
     *
     * Internal — used by sendInvoice (web), sendInvoice (mobile API) and
     * the payment_reminder_single auto-attach. Not exposed as a route.
     */
    public function readInvoiceImageUrlForSend($orderId): array
    {
        try {
            $disk = 'public';

            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($orderId);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            [$storagePath] = $this->findInvoiceImage($orderNum);

            if ($storagePath === null) {
                return [
                    'success' => false,
                    'needs_capture' => true,
                    'message' => 'Invoice image not generated yet. Please preview the invoice first.',
                    'order_number' => $orderNum,
                ];
            }

            // mtime cache buster: defeats both browser HTTP cache and Meta's
            // image cache. Same filename keeps the storage tidy; the URL is
            // unique per overwrite which is all Meta cares about.
            $mtime = Storage::disk($disk)->lastModified($storagePath);
            $base = request()->getSchemeAndHttpHost();
            return [
                'success' => true,
                'image_url' => rtrim($base, '/') . '/public-storage/' . $storagePath . '?v=' . $mtime,
                'order_number' => $orderNum,
            ];
        } catch (\Exception $e) {
            Log::error('readInvoiceImageUrlForSend failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Upload the already-captured invoice PNG straight to Meta's media
     * endpoint and return its media_id.
     *
     * WHY (Jun-2026): the legacy send flow handed Meta a `link` URL
     * pointing at our /public-storage/{path} route. That route is NOT a
     * static file — it boots the full Laravel kernel through
     * FileController::publicStorage() on every hit. When Meta's servers
     * fetch that link they get a short, unforgiving budget; on shared
     * hosting (stackcp) a momentary PHP-worker contention spike makes
     * Meta's fetch time out and the send fails with the intermittent
     * "media upload error" staff were seeing. There is no storage
     * symlink in this environment, so every image fetch pays that PHP
     * cost.
     *
     * Pushing the bytes to Meta ourselves removes Meta's need to fetch
     * anything from us — this is a server→Meta outbound POST (60s
     * budget, fully under our control), so it is dramatically more
     * reliable. Meta then references the returned media_id directly in
     * the template header.
     *
     * Returns:
     *   ['success'=>true,  'media_id'=>..., 'order_number'=>...]
     *   ['success'=>false, 'needs_capture'=>true, 'message'=>..., 'order_number'=>...]  // file not on disk yet
     *   ['success'=>false, 'message'=>...]                                              // upload to Meta failed
     *
     * Internal — used by buildInvoiceHeaderForSend(). Not a route.
     */
    public function uploadInvoiceImageToMetaForSend($orderId): array
    {
        try {
            $disk = 'public';

            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($orderId);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            [$storagePath, $mime] = $this->findInvoiceImage($orderNum);

            if ($storagePath === null) {
                return [
                    'success' => false,
                    'needs_capture' => true,
                    'message' => 'Invoice image not generated yet. Please preview the invoice first.',
                    'order_number' => $orderNum,
                ];
            }

            // Absolute on-disk path for the multipart upload. Meta's
            // media endpoint wants the raw bytes, not a URL. The mime must
            // match the actual bytes (jpg vs png) or Meta rejects the upload.
            $localPath = Storage::disk($disk)->path($storagePath);
            $mediaId = app(WhatsAppService::class)->uploadMediaToWhatsApp($localPath, $mime);

            if (!$mediaId) {
                return [
                    'success' => false,
                    'message' => 'Failed to upload invoice image to WhatsApp.',
                    'order_number' => $orderNum,
                ];
            }

            return [
                'success' => true,
                'media_id' => $mediaId,
                'order_number' => $orderNum,
            ];
        } catch (\Exception $e) {
            Log::error('uploadInvoiceImageToMetaForSend failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Build the WhatsApp template header parameters for an invoice send.
     *
     * Prefers the robust media-ID upload (bytes pushed to Meta). If that
     * upload fails for a reason OTHER than the image not being captured
     * yet (e.g. a transient blip talking to Meta's media endpoint), it
     * transparently falls back to the legacy `link` header so a send is
     * never worse off than it was before this change.
     *
     * Shared by all three invoice send paths (web sendInvoice, mobile
     * sendInvoice, payment_reminder_single auto-attach) so they all get
     * the reliability fix and stay in lock-step.
     *
     * Returns:
     *   ['success'=>true,  'header_params'=>[...], 'order_number'=>...]
     *   ['success'=>false, 'needs_capture'=>bool, 'message'=>..., 'order_number'=>...]
     */
    public function buildInvoiceHeaderForSend($orderId): array
    {
        $viaId = $this->uploadInvoiceImageToMetaForSend($orderId);
        if ($viaId['success'] ?? false) {
            return [
                'success' => true,
                'header_params' => [
                    ['type' => 'image', 'image' => ['id' => $viaId['media_id']]],
                ],
                'order_number' => $viaId['order_number'] ?? null,
            ];
        }

        // Nothing on disk yet — there's no link to fall back to either,
        // so surface needs_capture so the UI shows "preview first".
        if ($viaId['needs_capture'] ?? false) {
            return $viaId;
        }

        // Media upload failed for another reason. Fall back to the
        // legacy link header so behaviour is no worse than before.
        $viaLink = $this->readInvoiceImageUrlForSend($orderId);
        if ($viaLink['success'] ?? false) {
            Log::warning('Invoice send: media-ID upload failed, fell back to link header', [
                'order_id' => $orderId,
            ]);
            return [
                'success' => true,
                'header_params' => [
                    ['type' => 'image', 'image' => ['link' => $viaLink['image_url']]],
                ],
                'order_number' => $viaLink['order_number'] ?? null,
            ];
        }

        return $viaLink;
    }

    /**
     * Send a manually-picked image into the conversation from the WEB
     * composer (Aug-2026).
     *
     * Delegates to the API controller's sendImageMessage(), which the NF
     * Messages APK already uses: same 5 MB / JPEG-PNG validation, same
     * content-sniffed mime check, same whatsapp-media storage layout, same
     * Meta media upload, and the same outbound `type=image` row shape — so a
     * picture sent from web and one sent from the app are indistinguishable
     * in the thread. Keeping ONE implementation means the 24-hour-window
     * rule and the orphan-file cleanup can never drift between the two.
     *
     * Why this exists: the invoice template renders as a WhatsApp IMAGE
     * header, which WhatsApp re-encodes on delivery. Sending the same file
     * as a normal attachment inside an open session gives staff a way to
     * push a copy that skips the template path entirely.
     */
    public function sendImageAttachment(Request $request, $conversationId)
    {
        return app(\App\Http\Controllers\API\WhatsAppController::class)
            ->sendImageMessage($request, $conversationId);
    }

    /**
     * Receive a base64 PNG from the browser after html2canvas capture.
     */
    public function uploadInvoiceImage(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'image_data' => 'required|string',
        ]);

        try {
            $dir = 'whatsapp-invoices';
            $disk = 'public';

            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($request->order_id);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            $filename = 'Invoice-' . $orderNum;

            // The capture pages send either image/jpeg (orders + messages,
            // Jul-2026 — smaller payloads) or image/png (older pages). Pick
            // the extension from the data-URL prefix so the stored bytes and
            // the extension/mime always agree (Meta rejects mismatches).
            $imageData = $request->image_data;
            $ext = 'png';
            if (str_contains($imageData, ',')) {
                [$prefix, $imageData] = explode(',', $imageData, 2);
                if (str_contains($prefix, 'image/jpeg') || str_contains($prefix, 'image/jpg')) {
                    $ext = 'jpg';
                }
            }
            $decoded = base64_decode($imageData);
            if (!$decoded) {
                return response()->json(['success' => false, 'message' => 'Invalid image data'], 422);
            }

            $storagePath = $dir . '/' . $filename . '.' . $ext;
            Storage::disk($disk)->put($storagePath, $decoded);

            // Delete the sibling extension so findInvoiceImage() can never
            // resolve a stale counterpart from before this capture.
            $sibling = $dir . '/' . $filename . '.' . ($ext === 'jpg' ? 'png' : 'jpg');
            if (Storage::disk($disk)->exists($sibling)) {
                Storage::disk($disk)->delete($sibling);
            }

            // Append mtime cache buster so the browser preview shows the
            // freshly-uploaded image (rather than a stale http-cached one
            // from a previous capture) and so any subsequent send picks
            // up the new URL too. See readInvoiceImageUrlForSend() for
            // the full rationale.
            $mtime = Storage::disk($disk)->lastModified($storagePath);
            $base = request()->getSchemeAndHttpHost();
            return response()->json([
                'success' => true,
                'image_url' => rtrim($base, '/') . '/public-storage/' . $storagePath . '?v=' . $mtime,
                'order_number' => $orderNum,
            ]);

        } catch (\Exception $e) {
            Log::error('Invoice image upload failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send invoice via WhatsApp template with image header
     */
    public function sendInvoice(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'phone' => 'required|string',
            'template_name' => 'required|string',
            'body_params' => 'nullable|array',
            'conversation_id' => 'nullable|integer',
        ]);

        try {
            $service = app(WhatsAppService::class);

            // Jul-2026: dial-resolve (known-number override; no-op for PK)
            // with the phone_exact manual-override seam — see sendTemplate.
            $conversation = $request->conversation_id
                ? ConversationModel::find($request->conversation_id)
                : null;
            $phone = $request->boolean('phone_exact')
                ? preg_replace('/\D/', '', (string) $request->phone)
                : $service->resolveDialPhone((string) $request->phone, $conversation);

            // Jun-2026: upload the freshly-captured invoice PNG straight
            // to Meta and reference it by media_id, instead of handing
            // Meta a /public-storage/ link to fetch. That fetch was the
            // source of the intermittent "media upload error" (Meta's
            // short fetch budget vs our PHP-served, symlink-less storage
            // route on shared hosting). See buildInvoiceHeaderForSend().
            //
            // The preview flow on every UI page (orders, qurbani,
            // messages, customers, approvals) auto-captures + uploads
            // BEFORE the user can click Send, so by the time we get here
            // the file on disk reflects the order being sent.
            $imgData = $this->buildInvoiceHeaderForSend($request->order_id);

            if (!($imgData['success'] ?? false)) {
                $isNeedsCapture = $imgData['needs_capture'] ?? false;
                $statusCode = $isNeedsCapture ? 422 : 500;
                $message = $imgData['message'] ?? 'Failed to read invoice image';
                // needs_capture lets the orders page auto-capture once and
                // retry instead of surfacing a dead-end failure banner.
                return response()->json(['success' => false, 'needs_capture' => $isNeedsCapture, 'message' => $message], $statusCode);
            }

            $headerParams = $imgData['header_params'];
            $orderNumber = $imgData['order_number'];

            $bodyParams = $request->body_params ?? [];

            $result = $service->sendTemplateMessage($phone, $request->template_name, 'en', $bodyParams, $headerParams);

            if (!($result['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 422);
            }

            $conversation = $conversation ?: $service->findOrCreateConversation($phone);

            if ($conversation) {
                $paramText = implode(', ', $bodyParams);
                $invoiceMsg = $service->saveOutboundMessage(
                    $conversation->id,
                    $result,
                    'template',
                    "Invoice #{$orderNumber}" . ($paramText ? " ({$paramText})" : ''),
                    auth()->id(),
                    $request->template_name,
                    $bodyParams
                );
                // Stamp the order link so the orders page can flag "invoice sent"
                // (bulk-queried by related_order_number). Non-fatal.
                if ($invoiceMsg) {
                    try {
                        $invoiceMsg->related_order_number = $orderNumber;
                        $invoiceMsg->save();
                    } catch (\Throwable $e) {
                        Log::debug('Failed to stamp related_order_number on invoice msg', ['error' => $e->getMessage()]);
                    }
                }
            }

            return response()->json(['success' => true, 'order_number' => $orderNumber]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send invoice', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
