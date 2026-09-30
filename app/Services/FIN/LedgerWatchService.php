<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\LedgerWatchModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cash pill's engine — "has anyone else moved money on MY tills?" (Sep-2026).
 *
 * Origin: a Rs 150,000 vendor payment posted against NF Cash from a phone, correct by
 * every permission rule, spotted days later only because the balance looked wrong. The
 * pill does not block anything; it makes the same event arrive as a notification instead
 * of an archaeology exercise.
 *
 * ⭐ Scope = the accounts the viewer is TAGGED on (t_fin_account_users), so it is
 * self-targeting: Shabib watches NF Cash because he is on NF Cash. Somebody tagged on
 * nothing gets a count of 0 and the pill hides itself — the same "no access → no bulb"
 * contract the day-review pill uses, and the reason this needs no new permission.
 */
class LedgerWatchService
{
    /**
     * The drawer shows whole DAYS, not a row count (Sep-29, owner: "segregate it by date").
     * The first page is today + the 6 days before it; "Show earlier days" loads the next
     * 7-day block that actually has something in it. It replaced "last 10 records", which at
     * ~3 entries a day was only two or three days of headings.
     */
    public const WINDOW_DAYS = 7;

    /** Safety ceiling on one window's candidates — NF Cash runs ~140 a week, ~16 of them not his hand. */
    private const WINDOW_SCAN = 3000;

    /** How far back "is there anything earlier?" searches, in candidate rows (~8 months of NF Cash). */
    private const ANCHOR_SCAN = 5000;

    /**
     * ⭐ Pre-deploy C11 (Sep-29): the badge counts every unread row, however old, but the first
     * page only showed 7 days — and opening the drawer marks them ALL read. So an unread row
     * typed 10 days ago was counted, never shown, then silently cleared. The first page now
     * reaches back to the oldest unread day, but never further than this.
     */
    private const FIRST_PAGE_MAX_DAYS = 31;

    /**
     * ⭐⭐ Row types the pill IGNORES — the order pipeline.
     *
     * Measured on the replica before this list existed: of 420 rows on Shabib's accounts in
     * 14 days that were "not his hand", **280 were rider deliveries** — invoices, order
     * payments and credit grants posted to the ONLINE account and L1-approved by Taimur on
     * Online Approvals. Fifteen notifications a day about ordinary deliveries is how a manager
     * learns to ignore the bulb, and the one Rs 150,000 vendor payment drowns in them.
     *
     * Those rows already have their own surface (Online Approvals / Daily Closing). What this
     * pill is FOR is a manager's hand on a till — vendor payment, expense, transfer, salary,
     * advance, deposit, purchase, adjustment, reversal — and with the pipeline removed that is
     * exactly what is left (140 rows / 14 days, all Taimur's genuine payments).
     *
     * ⚠ The Hub's "Not me" filter deliberately does NOT apply this list: a ledger page must be
     * complete. Only the notification is curated.
     */
    public const ORDER_FLOW_TYPES = [
        LedgerModel::TYPE_INVOICE,
        LedgerModel::TYPE_ORDER_PAYMENT,
        'customer_credit_grant',
        LedgerModel::TYPE_TIP_COLLECTED,
    ];

    /**
     * ⭐⭐ The tills this person KEEPS — `is_keeper`, not "tagged on".
     *
     * The first cut watched every account the viewer was tagged on, and the owner's screenshot
     * (as Taimur, tagged on 7 accounts) showed why that is wrong: Qasim paying a vendor from the
     * NF Food till he runs, Shabib filing riders' petrol from NF Cash, and rider settlements into
     * Shabib's till that Shabib had already approved — none of it Taimur's concern, all of it
     * clutter, and the one entry that matters buried in it.
     *
     * "Tagged on" means *may spend from*. The pill is not about spending; it is about the drawer
     * you are answerable for. So it watches exactly the tills you are keeper of — today, Shabib
     * and NF Cash — and shows nothing to anybody else. Someone who wants their own till watched
     * ticks "Holds the cash" on it. Same source of truth as the check-out count.
     */
    public function watchedAccountIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // cash AND bank: watching is not counting — see TillCountService::keeperAccounts.
        return app(TillCountService::class)->keeperAccounts($userId, false)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** This person's "seen up to here" marker (0 = never looked). */
    public function watermark(int $userId): int
    {
        return (int) (LedgerWatchModel::where('user_id', $userId)->value('last_seen_ledger_id') ?? 0);
    }

    /**
     * How many rows are waiting for him.
     *
     * ⚠ The "not my hand" test needs `approved_by` as well as the actor, so it cannot be
     * done in SQL without duplicating the rule (see LedgerModel::isSomeoneElsesHand — the
     * one place it lives). Instead SQL narrows to the cheap part — my accounts, newer than
     * my watermark, actually applied — and the rule runs in PHP over what survives. That
     * set is tiny by construction: it is bounded by what has happened since he last looked.
     */
    public function unread(int $userId, ?int $limit = null): Collection
    {
        $accountIds = $this->watchedAccountIds($userId);
        if (empty($accountIds)) {
            return collect();
        }

        $since = $this->watermark($userId);

        // Count path: no relations. The "whose hand" test reads three columns on the row itself,
        // and this is polled once a minute by every tagged manager on every page.
        //
        // ⭐ First day: with no watermark yet, "everything newer than 0" is months of history —
        // the bulb would open on "200" and mean nothing. Until he has looked once, only the
        // last 7 days count as unread. One open sets the real watermark and this never applies again.
        $rows = $this->rowsFor($accountIds, $userId, $limit ?? 200, $since, false)
            ->filter(fn (LedgerModel $r) => $this->isUnread($r, $since));

        return $rows->take($limit ?? PHP_INT_MAX)->values();
    }

    /**
     * The ONE unread test, so the badge and the drawer's dots can never disagree (they did:
     * badge "2", six dots — the first-day floor was applied to one and not the other).
     */
    private function isUnread(LedgerModel $r, int $since): bool
    {
        if ((int) $r->id <= $since) {
            return false;
        }
        if ($since === 0) {
            return $r->created_at && $r->created_at->gte(now()->subDays(7));
        }
        return true;
    }

    public function unreadCount(int $userId): int
    {
        return $this->unread($userId)->count();
    }

    /**
     * The drawer's list: one block of WINDOW_DAYS days of rows on his accounts that were not
     * his own hand, READ OR NOT, each flagged with whether it is new since he last looked.
     *
     * ⭐ Showing read ones too is deliberate. A notification that empties itself the moment
     * it is opened is useless the second time someone asks "what was that payment again?".
     *
     * ⭐ Days are the day it was TYPED (`created_at`), not the date it is filed under (owner's
     * ruling, Sep-29). The list is in typed order, so typed-day headings stay in order; a
     * backdated row sits under the day it was typed and keeps its "dated Sep 21" line.
     *
     * $before = null → today and the 6 days before it. Otherwise a Y-m-d, EXCLUSIVE: the block
     * is the 7 days ending the day before it. The drawer passes back `next_before` verbatim,
     * which is anchored on the newest earlier row — so "Show earlier days" skips empty weeks
     * instead of handing him a blank page. Blocks are whole days, so a day is never split
     * across two pages and its heading total is always the whole day.
     *
     * ⭐ C11: on the FIRST page an unread row typed before the 7 days pulls the block's start
     * back to that row's day (at most FIRST_PAGE_MAX_DAYS), so everything the badge counted is
     * on screen before the open marks it read.
     *
     * $since (display only) — the watermark the drawer saw BEFORE it opened (`seen_since` of
     * its first page). "Show earlier days" pages pass it back so their unread dots match the
     * first page's, although the open has since moved the real watermark. Accepted only when it
     * is not above the current watermark; anything else falls back to the current one.
     */
    public function recent(int $userId, ?string $before = null, ?int $since = null): array
    {
        $accountIds = $this->watchedAccountIds($userId);
        if (empty($accountIds)) {
            return ['items' => [], 'unread' => 0, 'latest_id' => 0, 'watching' => 0, 'next_before' => null, 'seen_since' => 0];
        }

        $end   = $before !== null ? Carbon::parse($before)->startOfDay() : now()->startOfDay()->addDay();
        $start = $end->copy()->subDays(self::WINDOW_DAYS);

        $current = $this->watermark($userId);
        $since   = ($since !== null && $since >= 0 && $since <= $current) ? $since : $current;

        if ($before === null) {
            $oldestUnread = $this->oldestUnreadDayBefore(
                $accountIds, $userId, $start, $end->copy()->subDays(self::FIRST_PAGE_MAX_DAYS), $since
            );
            if ($oldestUnread !== null) {
                $start = $oldestUnread;
            }
        }
        // Filter on bare columns first, THEN load relations for the handful that survive —
        // most of a keeper's own till is his own hand (~140 candidates → ~16 shown a week).
        $shown = $this->windowRows($accountIds, $userId, $start, $end);
        $shown->load(['fromAccount', 'toAccount', 'enteredBy', 'createdBy']);
        // web / mobile / system for all of them in ONE query — per-row lookups here would
        // be a round trip per row every time the drawer opens.
        $sources = $this->sourcesFor($shown->pluck('id')->all());
        $earlier = $this->newestDayBefore($accountIds, $userId, $start);

        $items = $shown->map(function (LedgerModel $r) use ($accountIds, $since, $sources) {
            // Which of HIS accounts this row moved (a transfer can touch two; name the one
            // he watches, preferring the side the money left).
            $acct = in_array((int) $r->from_account_id, $accountIds, true)
                ? $r->fromAccount
                : $r->toAccount;
            $acctId = (int) ($acct->id ?? 0);

            return [
                'id'             => (int) $r->id,
                'account'        => $acct->account_name ?? '—',
                'type'           => LedgerModel::typeLabel($r->transaction_type),
                'description'    => \Illuminate\Support\Str::limit((string) $r->description, 70),
                'amount'         => (float) $r->amount,
                'effect'         => $acctId ? round($r->effectOnAccount($acctId), 2) : 0.0,
                'who'            => $r->actorName(),
                'source'         => $sources[(int) $r->id] ?? null,
                'typed_at'       => $r->created_at?->format('M j, H:i'),
                // The heading this row sits under, and the time shown on the card beneath it.
                'day'            => $r->created_at?->toDateString(),
                'day_label'      => $this->dayLabel($r->created_at),
                'time'           => $r->created_at?->format('H:i'),
                'shows_as'       => $r->transaction_date
                    ? \Carbon\Carbon::parse($r->transaction_date)->format('M j')
                    : null,
                'days_backdated' => $r->daysBackdated(),
                'unread'         => $this->isUnread($r, $since),
                'url'            => $acctId
                    ? route('fin.hub.account', ['id' => $acctId]) . '#txn-' . $r->id
                    : route('fin.ledger.show', $r->id),
            ];
        })->values()->all();

        return [
            'items'       => $items,
            'unread'      => $shown->filter(fn ($r) => $this->isUnread($r, $since))->count(),
            'latest_id'   => (int) (LedgerModel::max('id') ?? 0),
            'watching'    => count($accountIds),
            // The block just returned (first + last day), so an empty page can say which days.
            'from'        => $start->toDateString(),
            'to'          => $end->copy()->subDay()->toDateString(),
            // What to pass as $before for "Show earlier days"; null = nothing earlier.
            'next_before' => $earlier?->copy()->addDay()->toDateString(),
            // The watermark these dots were drawn against — the drawer passes it back as
            // `since` on "Show earlier days" (C11).
            'seen_since'  => $since,
        ];
    }

    /**
     * The typed day of the OLDEST unread not-his-hand row typed in [$floor, $start), or null.
     * Same isUnread() rule as the badge; bounded by WINDOW_SCAN like a window.
     */
    private function oldestUnreadDayBefore(array $accountIds, int $userId, Carbon $start, Carbon $floor, int $since): ?Carbon
    {
        $hit = $this->candidates($accountIds)
            ->where('created_at', '>=', $floor)
            ->where('created_at', '<', $start)
            // the cheap half of isUnread() in SQL; the rest (first-day floor) runs below
            ->where('id', '>', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::WINDOW_SCAN)
            ->get()
            ->first(fn (LedgerModel $r) => $r->isSomeoneElsesHand($userId) && $this->isUnread($r, $since));

        return $hit?->created_at?->copy()->startOfDay();
    }

    /** "Today" / "Yesterday" / "Sun, Sep 28" — the year only when it is not this year. */
    private function dayLabel(?Carbon $at): ?string
    {
        if (!$at) {
            return null;
        }
        if ($at->isToday()) {
            return 'Today';
        }
        if ($at->isYesterday()) {
            return 'Yesterday';
        }

        return $at->format($at->year === now()->year ? 'D, M j' : 'D, M j, Y');
    }

    /** Every not-his-hand row TYPED in [$start, $end), newest first. */
    private function windowRows(array $accountIds, int $userId, Carbon $start, Carbon $end): Collection
    {
        return $this->candidates($accountIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::WINDOW_SCAN)
            ->get()
            ->filter(fn (LedgerModel $r) => $r->isSomeoneElsesHand($userId))
            ->values();
    }

    /**
     * The typed day of the newest not-his-hand row before $before, or null if there is none
     * within ANCHOR_SCAN candidates. Walked in chunks because the rows that qualify are the
     * minority — on his own till most candidates are his own hand.
     */
    private function newestDayBefore(array $accountIds, int $userId, Carbon $before): ?Carbon
    {
        $scanned = 0;
        $belowId = null;
        while ($scanned < self::ANCHOR_SCAN) {
            $chunk = $this->candidates($accountIds)
                ->where('created_at', '<', $before)
                ->when($belowId !== null, fn ($q) => $q->where('id', '<', $belowId))
                ->orderByDesc('id')
                ->limit(500)
                ->get();
            if ($chunk->isEmpty()) {
                return null;
            }
            $hit = $chunk->first(fn (LedgerModel $r) => $r->isSomeoneElsesHand($userId));
            if ($hit) {
                return $hit->created_at->copy()->startOfDay();
            }
            $scanned += $chunk->count();
            $belowId  = (int) $chunk->last()->id;
        }

        return null;
    }

    /**
     * Mark everything up to $ledgerId as seen.
     *
     * ⭐ Owner's rule: "once Shabib views it, it can be read". Called when the drawer opens,
     * with the newest id the SERVER knows about — not the newest id in the list — so a row
     * that landed between the list being built and the drawer opening is not silently
     * skipped over.
     */
    public function markSeen(int $userId, ?int $ledgerId = null): int
    {
        $id = $ledgerId ?: (int) (LedgerModel::max('id') ?? 0);
        $current = $this->watermark($userId);
        // Never walk the watermark backwards — two tabs open would otherwise resurrect
        // rows the person has already dealt with.
        $id = max($id, $current);

        LedgerWatchModel::updateOrCreate(
            ['user_id' => $userId],
            ['last_seen_ledger_id' => $id, 'last_seen_at' => now()]
        );

        return $id;
    }

    /**
     * Shared query: applied rows on $accountIds, newest first, that are not $userId's hand.
     * `$since` narrows to the unread tail; null means "however far back you need to look".
     */
    private function rowsFor(array $accountIds, int $userId, int $scan, ?int $since, bool $withRelations = true): Collection
    {
        $q = $this->candidates($accountIds)
            ->when($withRelations, fn ($qq) => $qq->with(['fromAccount', 'toAccount', 'enteredBy', 'createdBy']));

        if ($since !== null) {
            $q->where('id', '>', $since);
        }

        return $q->orderByDesc('id')
            ->limit($scan)
            ->get()
            ->filter(fn (LedgerModel $r) => $r->isSomeoneElsesHand($userId))
            ->values();
    }

    /**
     * The SQL half of "should he hear about this" — his accounts, applied, not the order
     * pipeline. ONE place, so the badge and every drawer page apply the same rules. The
     * "not my hand" half runs in PHP over what this returns (see unread()).
     */
    private function candidates(array $accountIds): Builder
    {
        return LedgerModel::query()
            ->where(function ($w) use ($accountIds) {
                $w->whereIn('from_account_id', $accountIds)
                  ->orWhereIn('to_account_id', $accountIds);
            })
            // Only money that actually moved. A pending row has not changed the balance
            // he is being asked to watch, and announcing it would be a second, competing
            // approvals queue.
            ->where('balance_updated', 1)
            // Not the order pipeline — see ORDER_FLOW_TYPES.
            ->whereNotIn('transaction_type', self::ORDER_FLOW_TYPES);
    }

    /**
     * web / mobile / system per ledger id, read off the audit trail — the ledger row itself
     * does not record which surface posted it, and "Taimur, on his phone" is most of the
     * value of the notification.
     */
    private function sourcesFor(array $ledgerIds): array
    {
        if (empty($ledgerIds)) {
            return [];
        }

        return DB::table('t_sys_audit_log')
            ->where('entity_type', 'ledger')
            ->where('action', 'created')
            ->whereIn('entity_id', $ledgerIds)
            ->pluck('source', 'entity_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
