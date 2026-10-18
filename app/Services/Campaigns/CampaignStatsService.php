<?php

namespace App\Services\Campaigns;

use App\Services\CRM\ProductCategoryRollup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every campaign number the web page and the mobile app display comes from
 * here. One implementation, so the two surfaces cannot drift (they did before:
 * mobile honoured product tracking, web silently ignored it).
 *
 * Everything is SET-BASED. The previous implementation ran two queries per sent
 * customer, so a 5,700-recipient campaign meant ~11,000 queries and a dead
 * modal. These are a fixed handful of queries regardless of campaign size.
 *
 * The funnel:
 *
 *   Sent      — Meta accepted the message
 *   Delivered — it reached the handset (Meta webhook)
 *   Read      — the customer opened it (Meta webhook). A FLOOR, not a truth:
 *               customers who disable read receipts never report this, so read
 *               is always <= reality. Never present it as "N people ignored us".
 *   Replied   — they sent us a WhatsApp message back inside the window
 *   Ordered   — they placed a qualifying order inside the window
 *
 * Delivery data only exists for sends made after the Jul-2026 revamp (older
 * rows have no wa_message_id), so every funnel reports `receipts_tracked` and
 * the UI says so rather than showing a misleading zero.
 */
class CampaignStatsService
{
    /**
     * Statuses that DISQUALIFY an order from counting as a campaign conversion.
     *
     * Owner ruling Aug-2026: a campaign's job ends when the customer places an
     * order — everything after that is fulfilment. The rule used to be an
     * inclusion list ('delivered','completed'), which meant a fresh order sat
     * uncounted until it was delivered, so a working campaign showed "0 ordered"
     * for days. Real case: a customer read the app-launch message and ordered
     * from the app within the hour (SH-21640, iOS) — the exact win the campaign
     * existed to produce — and the screen reported 0% because the order was
     * still `on_hold`.
     *
     * So it is now an EXCLUSION list: every status counts except the ones that
     * mean the sale did not stand.
     *   cancelled — the order was called off; never a win.
     *   refunded  — the money went back; counting it would overstate results.
     *               (Zero rows today, so this changes nothing now; it is here so
     *               the rule stays correct when refunds do appear.)
     *
     * CONSEQUENCE, deliberate: campaign revenue now includes orders that have
     * not been delivered yet, so it will run AHEAD of the HQ/report revenue
     * figures, which stay delivered-only. That is the intended trade — the
     * campaign screen answers "did this message produce orders?", not "how much
     * cash has landed?". The funnel labels say so out loud.
     *
     * NOTE this does NOT govern customer lifetime spend used by the targeting
     * filters and the spend sort — that stays delivered-only in
     * CampaignFilterService::orderStatsSubquerySql(), because "has spent 50k
     * with us" must mean money actually received.
     */
    private const NON_CONVERTING_STATUSES = ['cancelled', 'refunded'];

    /** Shopify source_name values that mean "placed in the customer app". */
    private const APP_CHANNELS = ['ios_app', 'android_app'];

    /**
     * An active campaign with people still waiting and no send for this long is
     * treated as forgotten and surfaced on the landing view. Long enough that a
     * deliberately paced campaign (a batch a week) is never flagged.
     */
    public const STALE_DAYS = 60;

    // =====================================================================
    // Single campaign
    // =====================================================================

    public function forCampaign(int $campaignId): ?array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return null;

        $window = (int) ($campaign->tracking_window_days ?: 30);

        $delivery   = $this->deliveryFunnel([$campaignId]);
        $conversion = $this->conversions([$campaignId], $window, $campaign);

        return [
            'campaign_id'          => $campaignId,
            'campaign_name'        => $campaign->name,
            'wa_template_name'     => $campaign->wa_template_name,
            'tracking_type'        => $campaign->tracking_type ?: 'general',
            'tracked_categories'   => $this->trackedCategories($campaign),
            'tracking_window_days' => $window,
            'funnel'               => $this->assembleFunnel($delivery, $conversion),
            'engagement'           => $this->engagementCounts($campaignId),
            'source_split'         => $conversion['source_split'],
            'product_breakdown'    => $this->productBreakdown([$campaignId], $window, $campaign),
            'category_breakdown'   => $this->categoryBreakdown([$campaignId], $window),
            'tracking_note'        => $this->trackingNote($campaign),
        ];
    }

    /**
     * Delivery/reply funnel for one or more campaigns.
     *
     * $unique=true collapses to one row per customer (MAX over the flags =
     * "ever delivered / ever read / ever replied"), which is what the combined
     * per-template view needs so somebody messaged by three campaigns counts
     * once.
     */
    public function deliveryFunnel(array $campaignIds, bool $unique = false): array
    {
        if (empty($campaignIds)) {
            return ['sends' => 0, 'customers' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'undelivered' => 0, 'receipts_tracked' => 0];
        }

        $ph = implode(',', array_fill(0, count($campaignIds), '?'));

        // delivered counts read too: a read receipt proves delivery even when
        // the delivered webhook was missed or arrived out of order.
        $flags = "
            MAX(CASE WHEN cc.delivered_at IS NOT NULL OR cc.read_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
            MAX(CASE WHEN cc.read_at        IS NOT NULL THEN 1 ELSE 0 END) AS rd,
            MAX(CASE WHEN cc.replied_at     IS NOT NULL THEN 1 ELSE 0 END) AS replied,
            MAX(CASE WHEN cc.undelivered_at IS NOT NULL THEN 1 ELSE 0 END) AS undelivered,
            MAX(CASE WHEN cc.wa_message_id  IS NOT NULL THEN 1 ELSE 0 END) AS tracked";

        $rows = DB::select("
            SELECT
                COUNT(*)              AS customers,
                SUM(u.sends)          AS sends,
                SUM(u.delivered)      AS delivered,
                SUM(u.rd)             AS rd,
                SUM(u.replied)        AS replied,
                SUM(u.undelivered)    AS undelivered,
                SUM(u.tracked)        AS receipts_tracked
            FROM (
                SELECT cc.customer_id, COUNT(*) AS sends, {$flags}
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph})
                  AND cc.status = 'sent'
                GROUP BY cc.customer_id
            ) u
        ", $campaignIds);

        $r = $rows[0] ?? null;

        return [
            'sends'            => (int) ($r->sends ?? 0),
            'customers'        => (int) ($r->customers ?? 0),
            'delivered'        => (int) ($r->delivered ?? 0),
            'read'             => (int) ($r->rd ?? 0),
            'replied'          => (int) ($r->replied ?? 0),
            'undelivered'      => (int) ($r->undelivered ?? 0),
            'receipts_tracked' => (int) ($r->receipts_tracked ?? 0),
        ];
    }

    /**
     * Orders attributed to a set of campaigns.
     *
     * Attribution rule (owner ruling, Jul-2026): a customer is credited to
     * their MOST RECENT send. That is what the MAX(sent_at) derived table does,
     * and it is why the same order can never be claimed by two campaigns in a
     * combined view.
     *
     * $campaignForRules supplies tracking_type / tracked_product_ids. When
     * several campaigns are combined we pass the newest one — they share a
     * template, so in practice they share intent too.
     */
    public function conversions(array $campaignIds, int $windowDays, $campaignForRules = null): array
    {
        $empty = [
            'converters' => 0, 'orders' => 0, 'revenue' => 0.0,
            'source_split' => ['app' => 0, 'web' => 0, 'manual' => 0],
        ];
        if (empty($campaignIds)) return $empty;

        $ph = implode(',', array_fill(0, count($campaignIds), '?'));

        // Extra conditions that decide what counts as a conversion.
        [$extra, $extraBindings] = $this->conversionFilter($campaignForRules);

        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";
        $appList    = "'" . implode("','", self::APP_CHANNELS) . "'";

        // Bindings order: window (derived table has none), campaigns, then
        // product ids if used, then window for DATE_ADD.
        $sql = "
            SELECT
                COUNT(DISTINCT l.customer_id) AS converters,
                COUNT(DISTINCT o.id)          AS orders,
                COALESCE(SUM(o.total_price), 0) AS revenue,
                COUNT(DISTINCT CASE WHEN o.order_source_channel IN ({$appList}) THEN o.id END) AS app_orders,
                COUNT(DISTINCT CASE WHEN (o.order_source_channel IS NULL OR o.order_source_channel NOT IN ({$appList}))
                                     AND (o.order_number LIKE 'SH-%' OR o.order_source_channel = 'web') THEN o.id END) AS web_orders
            FROM (
                SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph})
                  AND cc.status = 'sent'
                  AND cc.sent_at IS NOT NULL
                GROUP BY cc.customer_id
            ) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
             {$extra}
        ";

        // Binding order must follow the order the placeholders appear in the
        // SQL above: campaign ids (derived table), then the window in the
        // JOIN's DATE_ADD, then the bindings of {$extra} (product ids / types).
        $bindings = $campaignIds;
        $bindings[] = $windowDays;
        foreach ($extraBindings as $b) $bindings[] = $b;

        $rows = DB::select($sql, $bindings);
        $r = $rows[0] ?? null;
        if (!$r) return $empty;

        $orders = (int) $r->orders;
        $app    = (int) $r->app_orders;
        $web    = (int) $r->web_orders;

        return [
            'converters'   => (int) $r->converters,
            'orders'       => $orders,
            'revenue'      => round((float) $r->revenue, 2),
            'source_split' => [
                'app'    => $app,
                'web'    => $web,
                'manual' => max(0, $orders - $app - $web),
            ],
        ];
    }

    /** Per-product totals for a 'products' campaign. Empty for other types. */
    public function productBreakdown(array $campaignIds, int $windowDays, $campaign): array
    {
        if (($campaign->tracking_type ?? 'general') !== 'products') return [];
        $productIds = $this->trackedProductIds($campaign);
        if (empty($productIds) || empty($campaignIds)) return [];

        $ph  = implode(',', array_fill(0, count($campaignIds), '?'));
        $pph = implode(',', array_fill(0, count($productIds), '?'));
        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";

        $bindings = array_merge($campaignIds, [$windowDays], $productIds);

        $rows = DB::select("
            SELECT li.product_id,
                   p.title AS product_name,
                   SUM(li.quantity) AS total_qty,
                   SUM(li.price * li.quantity) AS total_value
            FROM (
                SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph}) AND cc.status = 'sent' AND cc.sent_at IS NOT NULL
                GROUP BY cc.customer_id
            ) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
            JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
            JOIN t_crm_prod_product p ON p.id = li.product_id
            WHERE li.product_id IN ({$pph})
            GROUP BY li.product_id, p.title
            ORDER BY total_value DESC
        ", $bindings);

        return array_map(fn($r) => [
            'product_id'   => (int) $r->product_id,
            'product_name' => $r->product_name,
            'total_qty'    => (float) $r->total_qty,
            'total_value'  => round((float) $r->total_value, 2),
        ], $rows);
    }

    /**
     * Per-recipient rows for the campaign detail list — paginated, and with the
     * conversion + delivery facts resolved in TWO queries for the whole page
     * rather than two per person.
     *
     * $sortBy / $sortDir override the campaign's stored send order for THIS
     * view. This is what lets the operator re-prioritise inside a campaign
     * ("show me highest spenders first, I'll send to the top 100") without
     * recreating it — the creation-time sort was frozen into filters_json and
     * there was no way to change perspective afterwards.
     *
     * Every row also carries the customer's LIVE lifetime spend and order count
     * (same definition as the campaign filters and the Customers page — the
     * stored total_spent/total_orders columns are stale for thousands of
     * customers). Without these on the row, sorting by spend would show a
     * ranking the operator can't see or verify.
     */
    public function customerRows(int $campaignId, string $statusFilter = 'pending', int $page = 1, int $perPage = 100, ?string $sortBy = null, ?string $sortDir = null): array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return ['customers' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        $filterSvc = app(\App\Services\CampaignFilterService::class);

        $q = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $campaignId);

        // Lists behind the clickable tiles. Ordered / Tapped / Conversation /
        // Waiting are computed sets (orders + chat log), the rest are columns.
        if (in_array($statusFilter, self::DERIVED_FILTERS, true)) {
            $ids = $this->customerIdsForDerivedFilter($campaignId, $statusFilter, $campaign);
            $q->where('cc.status', 'sent')->whereIn('cc.customer_id', $ids ?: [0]);
        } else {
            $this->applyStatusFilter($q, $statusFilter);
        }

        $total = (clone $q)->count();

        $q->select(
            'cc.id as campaign_customer_id', 'cc.customer_id',
            'cc.status as campaign_status', 'cc.sent_at', 'cc.sent_by', 'cc.error_message',
            'cc.replied_at', 'cc.delivered_at', 'cc.read_at', 'cc.undelivered_at', 'cc.wa_message_id',
            'cc.match_tags',
            'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized',
            'c.city', 'c.last_order_date', 'c.is_on_mobile_app'
        );
        // NOTE: is_shopify is deliberately NOT selected here. With the FIELD()
        // status ordering the sort is a filesort, and MariaDB evaluates select
        // expressions for EVERY candidate row before sorting — so an EXISTS in
        // this select ran ~5,600 times on the big campaign (~120ms/page) to
        // label the 100 rows that survive. It's attached after pagination
        // below, per page, using the same shared expression.

        // Sort: explicit override wins, else the campaign's stored send order.
        // Both pass through normalizeSort so legacy keys ('total_spent') and
        // junk input collapse to safe columns — `ORDER BY c.spent` on a raw
        // key was a 500 once already.
        $filters = json_decode($campaign->filters_json ?? '{}', true) ?: [];
        [$defBy, $defDir] = $filterSvc->normalizeSort($filters);
        [$sortBy, $sortDir] = $filterSvc->normalizeSort([
            'sort_by'  => $sortBy ?: $defBy,
            'sort_dir' => $sortDir ?: $defDir,
        ]);

        // Lifetime spend/orders strategy — two paths on purpose:
        //  - RANKING by spend/orders needs the aggregate across the WHOLE
        //    campaign before pagination, so the full derived table is joined.
        //    Costs ~0.5s on a 5,000-recipient campaign; acceptable for an
        //    explicit "rank by revenue" request.
        //  - Every other sort (the default on every open and tab switch) skips
        //    that join entirely; the page's ~100 rows get their lifetime stats
        //    from a second query SCOPED to just those ids (milliseconds).
        //    Joining the full aggregate unconditionally measured 300-700ms per
        //    load — the exact sluggishness this page was just cured of.
        $statsOrdering = in_array($sortBy, ['spent', 'orders'], true);

        $q->orderByRaw("FIELD(cc.status, 'pending', 'sending', 'failed', 'sent', 'skipped')");
        if ($statsOrdering) {
            $q->leftJoin(
                DB::raw('(' . $filterSvc->orderStatsSubquerySql() . ') as ls'),
                'ls.customer_id', '=', 'c.id'
            );
            $q->selectRaw('COALESCE(ls.spent_total, 0) as lifetime_spent, COALESCE(ls.orders_count, 0) as lifetime_orders');
            $col = $sortBy === 'spent' ? 'ls.spent_total' : 'ls.orders_count';
            $q->orderByRaw('COALESCE(' . $col . ', 0) ' . ($sortDir === 'asc' ? 'asc' : 'desc'));
        } else {
            $q->orderBy('c.' . $sortBy, $sortDir);
        }

        $page = max(1, $page);
        $rows = $q->forPage($page, $perPage)->get();

        // Shopify badge, page-scoped (see the note at the select above). One
        // query over exactly these ids, reusing shopifyExistsExpr so the
        // definition stays in its one place.
        if ($rows->isNotEmpty()) {
            $pageIds = implode(',', $rows->pluck('customer_id')->map(fn($v) => (int) $v)->all());
            $flagRows = DB::select(
                'SELECT c.id, (' . $filterSvc->shopifyExistsExpr('c') . ') AS is_shopify '
                . 'FROM t_crm_prod_customer c WHERE c.id IN (' . $pageIds . ')'
            );
            $shopifyById = [];
            foreach ($flagRows as $fr) $shopifyById[(int) $fr->id] = (int) $fr->is_shopify;
            foreach ($rows as $r) $r->is_shopify = $shopifyById[(int) $r->customer_id] ?? 0;
        }

        // Fast path: attach the page's lifetime stats via a query scoped to
        // exactly these ids (see the strategy note above).
        if (!$statsOrdering && $rows->isNotEmpty()) {
            $ids = $rows->pluck('customer_id')->map(fn($v) => (int) $v)->all();
            $statRows = DB::select('SELECT * FROM (' . $filterSvc->orderStatsSubquerySql($ids) . ') s');
            $byId = [];
            foreach ($statRows as $s) {
                $byId[(int) $s->customer_id] = $s;
            }
            foreach ($rows as $r) {
                $s = $byId[(int) $r->customer_id] ?? null;
                $r->lifetime_spent  = $s ? (float) $s->spent_total : 0.0;
                $r->lifetime_orders = $s ? (int) $s->orders_count : 0;
            }
        }

        // Resolve "did this person order after we messaged them" for the whole
        // page in one query.
        $sentRows = $rows->filter(fn($r) => $r->campaign_status === 'sent' && $r->sent_at);
        if ($sentRows->isNotEmpty()) {
            $orderMap = $this->ordersForCustomers(
                $sentRows->pluck('customer_id')->map(fn($v) => (int) $v)->all(),
                $campaignId,
                (int) ($campaign->tracking_window_days ?: 30),
                $campaign
            );
            foreach ($rows as $r) {
                $m = $orderMap[(int) $r->customer_id] ?? null;
                $r->order_count = $m ? (int) $m['orders'] : 0;
                $r->order_revenue = $m ? (float) $m['revenue'] : 0.0;
                $r->first_order_at = $m['first_order_at'] ?? null;
            }

            // Chat-log facts for the page (tap / wrote / team answered).
            $eng = $this->engagementMap($campaignId, $sentRows->pluck('customer_id')->map(fn($v) => (int) $v)->all());
            foreach ($rows as $r) {
                $e = $eng[(int) $r->customer_id] ?? null;
                $r->tapped_at    = $e['tapped_at'] ?? null;
                $r->wrote_at     = $e['wrote_at'] ?? null;
                $r->conversation = $e ? $e['conversation'] : false;
                $r->waiting      = $e ? $e['waiting'] : false;
            }
        } else {
            foreach ($rows as $r) {
                $r->order_count = 0; $r->order_revenue = 0.0; $r->first_order_at = null;
                $r->tapped_at = null; $r->wrote_at = null; $r->conversation = false; $r->waiting = false;
            }
        }

        return [
            'customers' => $rows->values(),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => (int) ceil($total / max(1, $perPage)),
            // The sort actually applied, so the UI can reflect it truthfully.
            'sort_by'   => $sortBy,
            'sort_dir'  => $sortDir,
        ];
    }

    /** Per-customer attributed order counts for one campaign (page-scoped). */
    protected function ordersForCustomers(array $customerIds, int $campaignId, int $windowDays, $campaign): array
    {
        if (empty($customerIds)) return [];

        $cph = implode(',', array_fill(0, count($customerIds), '?'));
        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";

        [$extra, $extraBindings] = $this->conversionFilter($campaign);

        $rows = DB::select("
            SELECT cc.customer_id,
                   COUNT(DISTINCT o.id) AS orders,
                   COALESCE(SUM(o.total_price), 0) AS revenue,
                   MIN(o.order_date) AS first_order_at
            FROM t_crm_campaign_customers cc
            JOIN t_crm_prod_order o
              ON o.customer_id = cc.customer_id
             AND o.order_date  > cc.sent_at
             AND o.order_date <= DATE_ADD(cc.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
             {$extra}
            WHERE cc.campaign_id = ?
              AND cc.status = 'sent'
              AND cc.customer_id IN ({$cph})
            GROUP BY cc.customer_id
        ", array_merge([$windowDays], $extraBindings, [$campaignId], $customerIds));

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->customer_id] = [
                'orders' => (int) $r->orders,
                'revenue' => (float) $r->revenue,
                'first_order_at' => $r->first_order_at,
            ];
        }
        return $out;
    }

    // =====================================================================
    // Per-template combined results
    // =====================================================================

    /**
     * Every campaign that used a template, each with its own funnel, PLUS one
     * combined funnel where each customer counts exactly once.
     *
     * This answers the two different questions the owner asked:
     *   - "how did each campaign fare?"   -> campaigns[]
     *   - "how did the TEMPLATE perform?" -> combined (deduplicated)
     *
     * The gap between combined.sends and combined.customers is the number of
     * repeat sends, surfaced as `duplicate_sends` so message fatigue is visible
     * instead of inflating the numbers.
     */
    public function forTemplate(string $templateName): array
    {
        $templateName = trim($templateName);
        if ($templateName === '') {
            return ['template_name' => '', 'campaigns' => [], 'combined' => null];
        }

        $campaigns = DB::table('t_crm_campaigns')
            ->where('wa_template_name', $templateName)
            ->orderByDesc('created_at')
            ->get();

        if ($campaigns->isEmpty()) {
            return ['template_name' => $templateName, 'campaigns' => [], 'combined' => null];
        }

        $perCampaign = [];
        foreach ($campaigns as $c) {
            $w = (int) ($c->tracking_window_days ?: 30);
            $d = $this->deliveryFunnel([(int) $c->id]);
            $v = $this->conversions([(int) $c->id], $w, $c);
            $perCampaign[] = [
                'campaign_id'   => (int) $c->id,
                'campaign_name' => $c->name,
                'status'        => $c->status,
                'created_at'    => $c->created_at,
                'ended_at'      => $c->ended_at,
                'first_sent_at' => DB::table('t_crm_campaign_customers')->where('campaign_id', $c->id)->where('status', 'sent')->min('sent_at'),
                'last_sent_at'  => DB::table('t_crm_campaign_customers')->where('campaign_id', $c->id)->where('status', 'sent')->max('sent_at'),
                'tracking_window_days' => $w,
                'tracking_type' => $c->tracking_type ?: 'general',
                'funnel'        => $this->assembleFunnel($d, $v),
                'source_split'  => $v['source_split'],
            ];
        }

        $c = $this->combinedForCampaigns($campaigns);

        return [
            'template_name'          => $templateName,
            'template_display_name'  => app(\App\Services\CampaignFilterService::class)->lookupTemplateDisplayName($templateName),
            'campaign_count'         => count($perCampaign),
            'campaigns'              => $perCampaign,
            'combined'               => $c['combined'],
            'combined_source_split'  => $c['source_split'],
            'tracking_window_days'   => $c['window'],
            'window_mixed'           => $c['window_mixed'],
        ];
    }

    /**
     * Everyone who has received a template, however it was sent — one row per
     * customer, with their most recent send.
     *
     * WHY this is not just the campaign rows: the same template also goes out by
     * hand from the chat window ("you just ordered, here's the app"). Those sends
     * are real reach and real conversions, but they live only in t_wa_messages.
     * Judged on campaign rows alone, `eid_bookings` showed 716 people when ~1,205
     * messages had actually gone out — the template looked far less used than it
     * was.
     *
     * Manual sends TO SOMEONE IN A CAMPAIGN are adopted onto the campaign row at
     * send time (WhatsAppService::adoptManualSendIntoCampaigns), so they arrive
     * here through the campaign side. This union is what catches the rest: people
     * who were never in a campaign for this template at all.
     *
     * Double-counting is structurally impossible because the outer query groups
     * by customer_id — a person present in both halves still counts once, and
     * MAX() keeps the most recent send plus the best-known delivery state. That
     * matters because pre-Jul-2026 campaign rows have no wa_message_id, so they
     * cannot be matched to their message rows by id.
     *
     * Returns SQL for a derived table with:
     *   customer_id, sent_at, delivered, rd, replied, undelivered, tracked
     * Bindings required, in order: templateName, templateName.
     */
    protected function templateRecipientsSql(): string
    {
        return "
            SELECT u.customer_id,
                   MAX(u.sent_at)     AS sent_at,
                   MAX(u.delivered)   AS delivered,
                   MAX(u.rd)          AS rd,
                   MAX(u.replied)     AS replied,
                   MAX(u.undelivered) AS undelivered,
                   MAX(u.tracked)     AS tracked,
                   MAX(u.from_campaign) AS from_campaign
            FROM (
                -- (a) campaign recipients
                SELECT cc.customer_id,
                       cc.sent_at,
                       CASE WHEN cc.delivered_at IS NOT NULL OR cc.read_at IS NOT NULL THEN 1 ELSE 0 END AS delivered,
                       CASE WHEN cc.read_at        IS NOT NULL THEN 1 ELSE 0 END AS rd,
                       CASE WHEN cc.replied_at     IS NOT NULL THEN 1 ELSE 0 END AS replied,
                       CASE WHEN cc.undelivered_at IS NOT NULL THEN 1 ELSE 0 END AS undelivered,
                       CASE WHEN cc.wa_message_id  IS NOT NULL THEN 1 ELSE 0 END AS tracked,
                       1 AS from_campaign
                FROM t_crm_campaign_customers cc
                JOIN t_crm_campaigns c ON c.id = cc.campaign_id
                WHERE c.wa_template_name = ?
                  AND cc.status  = 'sent'
                  AND cc.sent_at IS NOT NULL

                UNION ALL

                -- (b) sends that exist only as chat messages. Delivery state comes
                -- from the message's own Meta status; 'replied' is any inbound
                -- message from that conversation after the send, which is the same
                -- thing the campaign webhook stamps as replied_at.
                SELECT conv.customer_id,
                       m.created_at,
                       CASE WHEN m.status IN ('delivered','read') THEN 1 ELSE 0 END,
                       CASE WHEN m.status = 'read'   THEN 1 ELSE 0 END,
                       CASE WHEN EXISTS (
                            SELECT 1 FROM t_wa_messages r
                            WHERE r.conversation_id = m.conversation_id
                              AND r.direction = 'inbound'
                              AND r.type NOT IN ('button', 'interactive')
                              AND r.created_at > m.created_at
                       ) THEN 1 ELSE 0 END,
                       CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END,
                       1,
                       0
                FROM t_wa_messages m
                JOIN t_wa_conversations conv ON conv.id = m.conversation_id
                WHERE m.direction = 'outbound'
                  AND m.template_name = ?
                  AND conv.customer_id IS NOT NULL
            ) u
            GROUP BY u.customer_id
        ";
    }

    /**
     * Reach + engagement for a template across BOTH send paths.
     * `sends` counts messages (from t_wa_messages, the authoritative log);
     * `customers` counts distinct people.
     */
    protected function templateDeliveryFunnel(string $templateName): array
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS customers,
                    SUM(delivered)   AS delivered,
                    SUM(rd)          AS rd,
                    SUM(replied)     AS replied,
                    SUM(undelivered) AS undelivered,
                    SUM(tracked)     AS receipts_tracked,
                    SUM(from_campaign) AS from_campaign
             FROM (' . $this->templateRecipientsSql() . ') t',
            [$templateName, $templateName]
        );
        $r = $rows[0] ?? null;

        // Total messages sent, straight from the message log — more accurate than
        // counting campaign rows, which miss manual sends and (pre-Jul-2026)
        // cannot be matched to their messages.
        $sends = (int) DB::table('t_wa_messages')
            ->where('direction', 'outbound')
            ->where('template_name', $templateName)
            ->count();

        $customers = (int) ($r->customers ?? 0);

        return [
            'sends'            => max($sends, $customers),
            'customers'        => $customers,
            'delivered'        => (int) ($r->delivered ?? 0),
            'read'             => (int) ($r->rd ?? 0),
            'replied'          => (int) ($r->replied ?? 0),
            'undelivered'      => (int) ($r->undelivered ?? 0),
            'receipts_tracked' => (int) ($r->receipts_tracked ?? 0),
            'from_campaign'    => (int) ($r->from_campaign ?? 0),
        ];
    }

    /**
     * Orders attributed to a template across both send paths, using each
     * customer's most recent send of it. Same conversion rules as a campaign.
     */
    protected function templateConversions(string $templateName, int $windowDays, $rulesCampaign): array
    {
        [$extra, $extraBindings] = $this->conversionFilter($rulesCampaign);

        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";
        $appList    = "'" . implode("','", self::APP_CHANNELS) . "'";

        // Bindings follow placeholder order: template, template (inside the
        // derived table), then the window, then the bindings of {$extra}.
        $bindings = [$templateName, $templateName, $windowDays];
        foreach ($extraBindings as $b) $bindings[] = $b;

        $rows = DB::select("
            SELECT COUNT(DISTINCT l.customer_id) AS converters,
                   COUNT(DISTINCT o.id)          AS orders,
                   COALESCE(SUM(o.total_price), 0) AS revenue,
                   COUNT(DISTINCT CASE WHEN o.order_source_channel IN ({$appList}) THEN o.id END) AS app_orders,
                   COUNT(DISTINCT CASE WHEN (o.order_source_channel IS NULL OR o.order_source_channel NOT IN ({$appList}))
                                        AND (o.order_number LIKE 'SH-%' OR o.order_source_channel = 'web') THEN o.id END) AS web_orders
            FROM (" . $this->templateRecipientsSql() . ") l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
             {$extra}
        ", $bindings);

        $r = $rows[0] ?? null;
        if (!$r) return ['converters' => 0, 'orders' => 0, 'revenue' => 0.0, 'source_split' => ['app' => 0, 'web' => 0, 'manual' => 0]];

        $orders = (int) $r->orders;
        $app = (int) $r->app_orders;
        $web = (int) $r->web_orders;

        return [
            'converters'   => (int) $r->converters,
            'orders'       => $orders,
            'revenue'      => round((float) $r->revenue, 2),
            'source_split' => ['app' => $app, 'web' => $web, 'manual' => max(0, $orders - $app - $web)],
        ];
    }

    /**
     * The combined (deduplicated) block for a set of campaigns.
     *
     * Extracted so the drill-down and the overview list compute it through the
     * SAME code path — otherwise the headline number on the overview row could
     * silently disagree with the number shown after clicking it, which is the
     * kind of inconsistency that destroys trust in a reporting screen.
     *
     * Rules baked in here:
     *  - Each customer counts once (deliveryFunnel with $unique = true).
     *  - Orders are credited to the customer's MOST RECENT send (owner ruling).
     *  - Where campaigns disagree on tracking window, the widest is used and
     *    `window_mixed` says so rather than hiding it.
     *  - Conversion rules (products / app_orders) come from the NEWEST campaign
     *    using the template — campaigns sharing a template share intent in
     *    practice, and the newest reflects current intent.
     */
    protected function combinedForCampaigns($campaigns): array
    {
        $ids     = $campaigns->pluck('id')->map(fn($v) => (int) $v)->all();
        $windows = $campaigns->pluck('tracking_window_days')->map(fn($v) => (int) ($v ?: 30))->unique()->values()->all();
        $window  = max($windows);
        $newest  = $campaigns->first();
        $templateName = (string) ($newest->wa_template_name ?? '');

        // Template-wide: campaign sends PLUS sends made by hand from the chat
        // window. Falls back to campaign-only if the template name is somehow
        // missing, so this can never return less than it used to.
        if ($templateName !== '') {
            $cd = $this->templateDeliveryFunnel($templateName);
            $cv = $this->templateConversions($templateName, $window, $newest);
        } else {
            $cd = $this->deliveryFunnel($ids, true);
            $cv = $this->conversions($ids, $window, $newest);
        }

        $combined = $this->assembleFunnel($cd, $cv);
        $combined['duplicate_sends'] = max(0, $cd['sends'] - $cd['customers']);
        // How much of the reach the campaign machinery itself produced, vs
        // templates sent by hand. Lets the UI say "716 of 1,190 via campaigns".
        $combined['from_campaign'] = $cd['from_campaign'] ?? $cd['customers'];
        $combined['outside_campaigns'] = max(0, $cd['customers'] - ($cd['from_campaign'] ?? $cd['customers']));

        return [
            'combined'     => $combined,
            'source_split' => $cv['source_split'],
            'window'       => $window,
            'window_mixed' => count($windows) > 1,
            'campaign_ids' => $ids,
        ];
    }

    /**
     * One combined summary row per template, for the campaigns landing view.
     *
     * Deliberately does NOT build the per-campaign breakdown — that is what the
     * drill-down is for. Cost is two queries per template (a handful in
     * practice), and it reuses combinedForCampaigns() so the row and the
     * drill-down headline are guaranteed identical.
     */
    public function templateSummaries(): array
    {
        $all = DB::table('t_crm_campaigns')
            ->whereNotNull('wa_template_name')
            ->where('wa_template_name', '!=', '')
            ->orderByDesc('created_at')
            ->get();

        if ($all->isEmpty()) return [];

        $filterSvc = app(\App\Services\CampaignFilterService::class);
        $out = [];

        foreach ($all->groupBy('wa_template_name') as $templateName => $campaigns) {
            $c = $this->combinedForCampaigns($campaigns);

            $sentAt = DB::table('t_crm_campaign_customers')
                ->whereIn('campaign_id', $c['campaign_ids'])
                ->where('status', 'sent')
                ->selectRaw('MIN(sent_at) as first_sent_at, MAX(sent_at) as last_sent_at')
                ->first();

            $out[] = [
                'wa_template_name'     => $templateName,
                'display_name'         => $filterSvc->lookupTemplateDisplayName($templateName),
                'campaign_count'       => $campaigns->count(),
                'active_count'         => $campaigns->where('status', 'active')->count(),
                'first_sent_at'        => $sentAt->first_sent_at ?? null,
                'last_sent_at'         => $sentAt->last_sent_at ?? null,
                'tracking_window_days' => $c['window'],
                'window_mixed'         => $c['window_mixed'],
                'tracking_type'        => $campaigns->first()->tracking_type ?: 'general',
                'funnel'               => $c['combined'],
                'source_split'         => $c['source_split'],
            ];
        }

        // Most recently used template first — that's what the operator is
        // working on today.
        usort($out, fn($a, $b) => strcmp((string) ($b['last_sent_at'] ?? ''), (string) ($a['last_sent_at'] ?? '')));

        return $out;
    }

    /**
     * The campaigns landing view: what's happening right now, how each template
     * is performing, and what needs a human.
     *
     * Answers the question the page is actually opened with ("how is my
     * messaging doing, and does anything need me?") instead of just listing
     * campaigns. Quota is added by the controller, which already owns that
     * payload shape.
     */
    public function overview(): array
    {
        $campaigns = DB::table('t_crm_campaigns')->orderByDesc('created_at')->get();

        // One aggregate for every campaign, rather than per-campaign subqueries.
        $agg = DB::table('t_crm_campaign_customers')
            ->selectRaw("
                campaign_id,
                MAX(sent_at) as last_sent_at,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status IN ('pending','sending') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='sent' AND undelivered_at IS NOT NULL THEN 1 ELSE 0 END) as undelivered
            ")
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $running   = [];
        $attention = [];
        $staleCutoff = now()->subDays(self::STALE_DAYS);

        foreach ($campaigns as $c) {
            $a = $agg->get($c->id);
            $pending     = (int) ($a->pending ?? 0);
            $failed      = (int) ($a->failed ?? 0);
            $undelivered = (int) ($a->undelivered ?? 0);
            $lastSentAt  = $a->last_sent_at ?? null;

            // --- sending right now -------------------------------------
            if (($c->send_state ?? 'idle') === 'running') {
                $run = $c->active_run_id
                    ? DB::table('t_crm_campaign_send_runs')->where('id', $c->active_run_id)->first()
                    : null;
                $running[] = [
                    'campaign_id'   => (int) $c->id,
                    'campaign_name' => $c->name,
                    'template'      => $c->wa_template_name,
                    'paused_reason' => $c->send_paused_reason,
                    'target'        => (int) ($run->target_count ?? 0),
                    'attempted'     => (int) ($run->attempted ?? 0),
                    'sent'          => (int) ($run->sent_count ?? 0),
                    'failed'        => (int) ($run->failed_count ?? 0),
                    'pending'       => $pending,
                ];
                continue; // a running campaign is not "needs attention"
            }

            if ($c->status !== 'active') continue;

            // --- needs a human -----------------------------------------
            if (($c->send_state ?? 'idle') === 'paused') {
                $attention[] = [
                    'type' => 'paused', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $c->send_paused_reason ?: 'Sending is paused.',
                    'action' => 'Open and press Resume',
                ];
            }
            if ($failed > 0) {
                $attention[] = [
                    'type' => 'failed', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $failed . ' message' . ($failed === 1 ? '' : 's') . ' failed to send',
                    'action' => 'Review and retry',
                    'count' => $failed,
                ];
            }
            // Without a template nothing can ever be sent, so this campaign is
            // stuck permanently rather than merely idle. Flag it as its own
            // problem instead of letting it read as "just needs a send".
            if ($pending > 0 && empty($c->wa_template_name)) {
                $attention[] = [
                    'type' => 'no_template', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => 'No WhatsApp template set, so its ' . $pending . ' waiting customers can never be messaged',
                    'action' => 'Re-create it with a template, or end it',
                    'count' => $pending,
                ];
            }
            if ($undelivered > 0) {
                $attention[] = [
                    'type' => 'undelivered', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $undelivered . ' could not be delivered (likely dead numbers)',
                    'action' => 'Review to clean up numbers',
                    'count' => $undelivered,
                ];
            }
            // A campaign left half-sent for months is almost always forgotten
            // rather than deliberate — surface it instead of letting it sit.
            // Skipped when there's no template: that's the 'no_template' case
            // above, and listing both would double-report one problem.
            if ($pending > 0 && !empty($c->wa_template_name) && (!$lastSentAt || $lastSentAt < $staleCutoff)) {
                $attention[] = [
                    'type' => 'stale', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $lastSentAt
                        ? $pending . ' still waiting, nothing sent since ' . substr((string) $lastSentAt, 0, 10)
                        : $pending . ' waiting and nothing has ever been sent',
                    'action' => 'Send the next batch, or end the campaign',
                    'count' => $pending,
                ];
            }
        }

        // Blocked first, then failures, then dead numbers, then forgotten.
        $order = ['paused' => 0, 'no_template' => 1, 'failed' => 2, 'undelivered' => 3, 'stale' => 4];
        usort($attention, fn($a, $b) => ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9));

        return [
            'running'   => $running,
            'attention' => $attention,
            'templates' => $this->templateSummaries(),
            'totals'    => [
                'active_campaigns' => $campaigns->where('status', 'active')->count(),
                'total_campaigns'  => $campaigns->count(),
                'pending_total'    => (int) $agg->sum('pending'),
                'sent_total'       => (int) $agg->sum('sent'),
            ],
        ];
    }

    /** Templates that have at least one campaign — for the template switcher. */
    public function templatesWithCampaigns(): array
    {
        $rows = DB::table('t_crm_campaigns as c')
            ->leftJoin('t_wa_templates as t', 't.name', '=', 'c.wa_template_name')
            ->whereNotNull('c.wa_template_name')
            ->where('c.wa_template_name', '!=', '')
            ->select(
                'c.wa_template_name',
                DB::raw('MAX(t.display_name) as display_name'),
                DB::raw('COUNT(*) as campaign_count'),
                DB::raw('MAX(c.created_at) as latest_campaign_at')
            )
            ->groupBy('c.wa_template_name')
            ->orderByDesc('latest_campaign_at')
            ->get();

        return $rows->map(fn($r) => [
            'wa_template_name' => $r->wa_template_name,
            'display_name'     => $r->display_name ?: $r->wa_template_name,
            'campaign_count'   => (int) $r->campaign_count,
            'latest_campaign_at' => $r->latest_campaign_at,
        ])->all();
    }

    // =====================================================================
    // Send-run history
    // =====================================================================

    public function runHistory(int $campaignId, int $limit = 20): array
    {
        if (!Schema::hasTable('t_crm_campaign_send_runs')) return [];

        return DB::table('t_crm_campaign_send_runs as r')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.started_by')
            ->where('r.campaign_id', $campaignId)
            ->orderByDesc('r.started_at')
            ->limit($limit)
            ->select('r.*', DB::raw("COALESCE(u.fullname, '') as started_by_name"))
            ->get()
            ->all();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    protected function assembleFunnel(array $delivery, array $conversion): array
    {
        $sent = $delivery['customers'];
        $pct = fn($n) => $sent > 0 ? round($n / $sent * 100, 1) : 0.0;

        return [
            'sends'            => $delivery['sends'],
            'sent'             => $sent,
            'delivered'        => $delivery['delivered'],
            'read'             => $delivery['read'],
            'replied'          => $delivery['replied'],
            'undelivered'      => $delivery['undelivered'],
            'ordered'          => $conversion['converters'],
            'orders'           => $conversion['orders'],
            'revenue'          => $conversion['revenue'],
            'receipts_tracked' => $delivery['receipts_tracked'],
            'rates' => [
                'delivered'  => $pct($delivery['delivered']),
                'read'       => $pct($delivery['read']),
                'replied'    => $pct($delivery['replied']),
                'ordered'    => $pct($conversion['converters']),
                'undelivered'=> $pct($delivery['undelivered']),
            ],
        ];
    }

    /**
     * A warning when a campaign's tracking setup can't do what its type claims.
     *
     * The real case: campaigns saved as tracking_type='products' before the
     * product picker existed have no tracked_product_ids, so the conversion
     * query adds no product condition and quietly counts EVERY order. The
     * number isn't wrong, but the label is — so say so instead of letting it
     * read as a product-level result.
     */
    protected function trackingNote($campaign): ?string
    {
        $type = $campaign->tracking_type ?? 'general';

        if ($type === 'products' && empty($this->trackedProductIds($campaign))) {
            return 'This campaign is set to track specific products, but no products were saved against it — so any order within the window is being counted. Use "Change tracking" to pick products or a category.';
        }

        if ($type === 'category') {
            $cats = $this->trackedCategories($campaign);
            if (empty($cats)) {
                return 'This campaign is set to track a category, but none is saved — so nothing counts as a conversion. Use "Change tracking" to pick one.';
            }
            return 'Only orders containing ' . implode(' / ', $cats) . ' products count as "Ordered". The "By category" table below shows every category, so you can still see who ordered something else.';
        }

        if ($type === 'app_orders') {
            return 'Only orders placed through the customer app count here. App orders are recognised from the order source captured at checkout, so orders placed before that tracking went live will not appear.';
        }

        return null;
    }

    protected function trackedProductIds($campaign): array
    {
        $raw = $campaign->tracked_product_ids ?? null;
        if (!$raw) return [];
        $ids = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($ids)) return [];
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * Categories (ProductCategoryRollup names) a 'category' campaign tracks.
     * The column arrives with the Oct-2026 SQL; before it exists the property
     * is simply absent and this returns [].
     */
    public function trackedCategories($campaign): array
    {
        $raw = $campaign->tracked_categories ?? null;
        if (!$raw) return [];
        $cats = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($cats)) return [];
        return array_values(array_filter(array_map(fn($c) => trim((string) $c), $cats), fn($c) => $c !== ''));
    }

    /**
     * The extra JOIN condition (on order alias `o`) that decides whether an
     * order counts as a conversion for this campaign, plus its bindings.
     *
     * ONE implementation for every conversion query — campaign totals, the
     * per-row "N after", the Ordered list and the per-template view — so the
     * strip, the list and the Results window can never disagree.
     *
     *   general    — any order (no extra condition)
     *   app_orders — placed through the customer app
     *   products   — contains one of the picked products (none picked = any
     *                order, flagged by trackingNote)
     *   category   — contains a product of the picked category, e.g. Frozen.
     *                None picked = NOTHING counts (also flagged), because
     *                silently counting every order is exactly the mistake
     *                this type exists to stop.
     */
    protected function conversionFilter($campaign): array
    {
        $type = $campaign->tracking_type ?? 'general';

        if ($type === 'app_orders') {
            $appList = "'" . implode("','", self::APP_CHANNELS) . "'";
            return [" AND o.order_source_channel IN ({$appList}) ", []];
        }

        if ($type === 'products') {
            $productIds = $this->trackedProductIds($campaign);
            if (empty($productIds)) return ['', []];
            $pph = implode(',', array_fill(0, count($productIds), '?'));
            return [" AND EXISTS (
                SELECT 1 FROM t_crm_prod_order_line_item cfli
                WHERE cfli.order_id = o.id AND cfli.product_id IN ({$pph})
            ) ", $productIds];
        }

        if ($type === 'category') {
            $types = ProductCategoryRollup::rawTypesFor($this->trackedCategories($campaign));
            if (empty($types)) return [' AND 1 = 0 ', []];
            $tph = implode(',', array_fill(0, count($types), '?'));
            return [" AND EXISTS (
                SELECT 1 FROM t_crm_prod_order_line_item cfli
                JOIN t_crm_prod_product cfp ON cfp.id = cfli.product_id
                WHERE cfli.order_id = o.id AND cfp.product_type IN ({$tph})
            ) ", $types];
        }

        return ['', []];
    }

    // =====================================================================
    // Oct-2026 — category results, reply split, lists, timeline
    // =====================================================================

    /**
     * What the people who ordered after the message actually bought, by
     * category — on EVERY campaign, whatever it tracks. This is what lets a
     * frozen campaign say "12 ordered something, 5 of them ordered frozen"
     * without having to decide up front, and it works retroactively.
     *
     * Same attribution as conversions(): most recent send per customer, the
     * campaign's window, cancelled/refunded excluded. `value` is the value of
     * THAT category's lines, not the whole order.
     *
     * `new_to_frozen` = frozen buyers with no frozen order before the
     * message. Only computed for Frozen ON PURPOSE: almost every 2025 line
     * item points at the pre-Mar-2026 catalog (unlinked), so "first time
     * buying mutton" would wrongly call most regulars new. Frozen lines are
     * all on the current catalog, so for Frozen it is exact.
     */
    public function categoryBreakdown(array $campaignIds, int $windowDays): array
    {
        $empty = ['rows' => [], 'any' => ['customers' => 0, 'orders' => 0], 'new_to_frozen' => 0, 'frozen_label' => ProductCategoryRollup::FROZEN];
        if (empty($campaignIds)) return $empty;

        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";
        $latestSend = "
            SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
            FROM t_crm_campaign_customers cc
            WHERE cc.campaign_id IN ({$ph}) AND cc.status = 'sent' AND cc.sent_at IS NOT NULL
            GROUP BY cc.customer_id";

        $orders = DB::select("
            SELECT l.customer_id, o.id AS oid
            FROM ({$latestSend}) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
        ", array_merge($campaignIds, [$windowDays]));

        if (empty($orders)) return $empty;

        $customerByOrder = [];
        foreach ($orders as $o) $customerByOrder[(int) $o->oid] = (int) $o->customer_id;

        $cats = [];
        foreach (array_chunk(array_keys($customerByOrder), 1000) as $chunk) {
            $lines = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->whereIn('li.order_id', $chunk)
                ->groupBy('li.order_id', 'p.product_type', DB::raw('(p.id IS NOT NULL)'))
                ->selectRaw('li.order_id, p.product_type AS ptype, (p.id IS NOT NULL) AS linked, COALESCE(SUM(li.line_total), 0) AS val')
                ->get();
            foreach ($lines as $ln) {
                $cat = $ln->linked ? ProductCategoryRollup::rollup($ln->ptype) : ProductCategoryRollup::UNLINKED;
                $oid = (int) $ln->order_id;
                $cats[$cat]['orders'][$oid] = true;
                $cats[$cat]['customers'][$customerByOrder[$oid]] = true;
                $cats[$cat]['value'] = ($cats[$cat]['value'] ?? 0) + (float) $ln->val;
            }
        }

        $rows = [];
        foreach ($cats as $cat => $c) {
            $rows[] = [
                'category'  => $cat,
                'customers' => count($c['customers'] ?? []),
                'orders'    => count($c['orders'] ?? []),
                'value'     => round((float) ($c['value'] ?? 0), 2),
            ];
        }
        usort($rows, fn($a, $b) => [$b['customers'], $b['value']] <=> [$a['customers'], $a['value']]);

        // First-time frozen buyers.
        $newToFrozen = 0;
        $frozenBuyers = array_keys($cats[ProductCategoryRollup::FROZEN]['customers'] ?? []);
        $frozenTypes  = ProductCategoryRollup::rawTypesFor([ProductCategoryRollup::FROZEN]);
        if (!empty($frozenBuyers) && !empty($frozenTypes)) {
            $bph = implode(',', array_fill(0, count($frozenBuyers), '?'));
            $tph = implode(',', array_fill(0, count($frozenTypes), '?'));
            $before = DB::select("
                SELECT DISTINCT l.customer_id
                FROM ({$latestSend}) l
                JOIN t_crm_prod_order o
                  ON o.customer_id = l.customer_id
                 AND o.order_date < l.sent_at
                 AND COALESCE(o.order_status, '') NOT IN ({$statusList})
                JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
                JOIN t_crm_prod_product p ON p.id = li.product_id
                WHERE l.customer_id IN ({$bph})
                  AND p.product_type IN ({$tph})
            ", array_merge($campaignIds, $frozenBuyers, $frozenTypes));
            $newToFrozen = count($frozenBuyers) - count($before);
        }

        return [
            'rows' => $rows,
            'any'  => [
                'customers' => count(array_unique(array_values($customerByOrder))),
                'orders'    => count($customerByOrder),
            ],
            'new_to_frozen' => max(0, $newToFrozen),
            'frozen_label'  => ProductCategoryRollup::FROZEN,
        ];
    }

    /**
     * What happened in WhatsApp after the message, per customer, read from
     * the chat log itself (t_wa_messages) inside the campaign's window:
     *
     *   tapped_at    — first button tap (e.g. "Menu"). Shown on its own: it is
     *                  interest, and the system answers it, so it is not a
     *                  conversation (owner ruling Oct-2026).
     *   replied_at   — first message that is not a tap. Reactions COUNT
     *                  (owner ruling). Same rule the webhook now stamps.
     *   wrote_at     — first message the customer actually wrote (not a tap,
     *                  not a reaction).
     *   team_last_at — last message a STAFF member sent (sent_by set), other
     *                  than this campaign's own template.
     *
     *   conversation = they wrote AND staff sent something after that.
     *   waiting      = they wrote and NO staff message followed. A later
     *                  "ok thanks" after the team answered stays a
     *                  conversation, so the list doesn't fill with closed chats.
     *
     * Keyed by customer_id. $customerIds limits it to one page of rows.
     */
    public function engagementMap(int $campaignId, ?array $customerIds = null): array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return [];
        if ($customerIds !== null && empty($customerIds)) return [];

        $bindings = [(string) ($campaign->wa_template_name ?? ''), (int) ($campaign->tracking_window_days ?: 30), $campaignId];
        $scope = '';
        if ($customerIds !== null) {
            $scope = ' AND cc.customer_id IN (' . implode(',', array_fill(0, count($customerIds), '?')) . ')';
            foreach ($customerIds as $cid) $bindings[] = (int) $cid;
        }

        $rows = DB::select("
            SELECT cc.customer_id,
                   MIN(CASE WHEN m.direction = 'inbound' AND m.type IN ('button','interactive') THEN m.created_at END) AS tapped_at,
                   MIN(CASE WHEN m.direction = 'inbound' AND m.type NOT IN ('button','interactive') THEN m.created_at END) AS replied_at,
                   MIN(CASE WHEN m.direction = 'inbound' AND m.type NOT IN ('button','interactive','reaction') THEN m.created_at END) AS wrote_at,
                   MAX(CASE WHEN m.direction = 'outbound' AND m.sent_by IS NOT NULL AND COALESCE(m.template_name, '') <> ? THEN m.created_at END) AS team_last_at
            FROM t_crm_campaign_customers cc
            JOIN t_wa_conversations cv ON cv.customer_id = cc.customer_id
            JOIN t_wa_messages m
              ON m.conversation_id = cv.id
             AND m.created_at > cc.sent_at
             AND m.created_at <= DATE_ADD(cc.sent_at, INTERVAL ? DAY)
            WHERE cc.campaign_id = ?
              AND cc.status = 'sent'
              AND cc.sent_at IS NOT NULL
              {$scope}
            GROUP BY cc.customer_id
        ", $bindings);

        $out = [];
        foreach ($rows as $r) {
            $conversation = $r->wrote_at && $r->team_last_at && $r->team_last_at > $r->wrote_at;
            $out[(int) $r->customer_id] = [
                'tapped_at'    => $r->tapped_at,
                'replied_at'   => $r->replied_at,
                'wrote_at'     => $r->wrote_at,
                'team_last_at' => $r->team_last_at,
                'conversation' => (bool) $conversation,
                'waiting'      => $r->wrote_at && !$conversation,
            ];
        }
        return $out;
    }

    /** Whole-campaign tallies of engagementMap(), for the strip and Results. */
    public function engagementCounts(int $campaignId): array
    {
        $map = $this->engagementMap($campaignId);
        $out = ['tapped' => 0, 'wrote' => 0, 'conversation' => 0, 'waiting' => 0];
        foreach ($map as $e) {
            if ($e['tapped_at']) $out['tapped']++;
            if ($e['wrote_at'])  $out['wrote']++;
            if ($e['conversation']) $out['conversation']++;
            if ($e['waiting']) $out['waiting']++;
        }
        return $out;
    }

    /**
     * Customers who count as "Ordered" for this campaign — the exact set
     * behind conversions()['converters'], so the Ordered tile and the list it
     * opens always show the same number.
     */
    public function convertedCustomerIds(int $campaignId, $campaign): array
    {
        $window = (int) ($campaign->tracking_window_days ?: 30);
        [$extra, $extraBindings] = $this->conversionFilter($campaign);
        $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";

        $rows = DB::select("
            SELECT DISTINCT l.customer_id
            FROM (
                SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id = ? AND cc.status = 'sent' AND cc.sent_at IS NOT NULL
                GROUP BY cc.customer_id
            ) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND COALESCE(o.order_status, '') NOT IN ({$statusList})
             {$extra}
        ", array_merge([$campaignId, $window], $extraBindings));

        return array_map(fn($r) => (int) $r->customer_id, $rows);
    }

    /** Filters that need a computed set of customers rather than a column. */
    public const DERIVED_FILTERS = ['ordered', 'tapped', 'conversation', 'waiting'];

    protected function customerIdsForDerivedFilter(int $campaignId, string $filter, $campaign): array
    {
        if ($filter === 'ordered') return $this->convertedCustomerIds($campaignId, $campaign);

        $ids = [];
        foreach ($this->engagementMap($campaignId) as $cid => $e) {
            if ($filter === 'tapped' && $e['tapped_at']) $ids[] = $cid;
            elseif ($filter === 'conversation' && $e['conversation']) $ids[] = $cid;
            elseif ($filter === 'waiting' && $e['waiting']) $ids[] = $cid;
        }
        return $ids;
    }

    /**
     * Everything that happened with ONE customer after the campaign message:
     * receipts, every WhatsApp message in the window (who sent it, what kind),
     * and every order in the window with its categories and whether it counts.
     */
    public function customerTimeline(int $campaignId, int $customerId): ?array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return null;

        $cc = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->where('customer_id', $customerId)
            ->orderByRaw("status = 'sent' DESC")
            ->orderByDesc('sent_at')
            ->first();
        if (!$cc) return null;

        $customer = DB::table('t_crm_prod_customer')->where('id', $customerId)
            ->select('id', 'first_name', 'last_name', 'phone', 'phone_normalized', 'city')->first();

        $window = (int) ($campaign->tracking_window_days ?: 30);
        $base = [
            'campaign' => [
                'id' => (int) $campaign->id, 'name' => $campaign->name,
                'template' => $campaign->wa_template_name,
                'tracking_type' => $campaign->tracking_type ?: 'general',
                'tracked_categories' => $this->trackedCategories($campaign),
                'window_days' => $window,
            ],
            'customer' => $customer,
            'row' => [
                'status' => $cc->status, 'sent_at' => $cc->sent_at,
                'delivered_at' => $cc->delivered_at ?? null, 'read_at' => $cc->read_at ?? null,
                'undelivered_at' => $cc->undelivered_at ?? null, 'replied_at' => $cc->replied_at ?? null,
                'error_message' => $cc->error_message ?? null,
            ],
            'messages' => [], 'orders' => [], 'summary' => null,
        ];
        if ($cc->status !== 'sent' || !$cc->sent_at) return $base;

        $sentAt = \Carbon\Carbon::parse($cc->sent_at);
        $until  = $sentAt->copy()->addDays($window);
        $template = (string) ($campaign->wa_template_name ?? '');

        // Messages: from a few minutes before the send (so the campaign
        // message itself shows) to the end of the window.
        $convIds = DB::table('t_wa_conversations')->where('customer_id', $customerId)->pluck('id')->all();
        $messages = [];
        if (!empty($convIds)) {
            $msgRows = DB::table('t_wa_messages as m')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'm.sent_by')
                ->whereIn('m.conversation_id', $convIds)
                ->where('m.created_at', '>=', $sentAt->copy()->subMinutes(5))
                ->where('m.created_at', '<=', $until)
                ->orderBy('m.created_at')
                ->limit(150)
                ->select('m.id', 'm.direction', 'm.type', 'm.content', 'm.template_name', 'm.sent_by',
                         'm.status', 'm.created_at', DB::raw("COALESCE(u.fullname, '') AS staff_name"))
                ->get();
            foreach ($msgRows as $m) {
                if ($m->direction === 'inbound') {
                    $kind = in_array($m->type, ['button', 'interactive'], true) ? 'tap'
                        : ($m->type === 'reaction' ? 'reaction' : 'wrote');
                } elseif ($template !== '' && $m->template_name === $template) {
                    $kind = 'campaign';
                } elseif ($m->sent_by) {
                    $kind = 'team';
                } else {
                    $kind = 'system';
                }
                $messages[] = [
                    'at' => $m->created_at, 'kind' => $kind, 'type' => $m->type,
                    'text' => mb_substr((string) $m->content, 0, 400),
                    'template' => $m->template_name ?: null,
                    'staff' => $m->staff_name ?: null,
                    'status' => $m->status,
                ];
            }
        }

        // Orders in the window — ALL of them, each saying whether it counts.
        $orderRows = DB::table('t_crm_prod_order')
            ->where('customer_id', $customerId)
            ->where('order_date', '>', $sentAt)
            ->where('order_date', '<=', $until)
            ->orderBy('order_date')
            ->select('id', 'order_number', 'order_date', 'total_price', 'order_status', 'order_source_channel')
            ->get();

        $orders = [];
        if ($orderRows->isNotEmpty()) {
            $oids = $orderRows->pluck('id')->map(fn($v) => (int) $v)->all();

            $catsByOrder = [];
            $lines = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->whereIn('li.order_id', $oids)
                ->select('li.order_id', 'p.product_type', DB::raw('(p.id IS NOT NULL) AS linked'))
                ->get();
            foreach ($lines as $ln) {
                $cat = $ln->linked ? ProductCategoryRollup::rollup($ln->product_type) : ProductCategoryRollup::UNLINKED;
                $catsByOrder[(int) $ln->order_id][$cat] = true;
            }

            // Which of them pass this campaign's conversion rule — through the
            // same conversionFilter() as every total, so the timeline agrees.
            [$extra, $extraBindings] = $this->conversionFilter($campaign);
            $statusList = "'" . implode("','", self::NON_CONVERTING_STATUSES) . "'";
            $oph = implode(',', array_fill(0, count($oids), '?'));
            $counting = DB::select("
                SELECT o.id FROM t_crm_prod_order o
                WHERE o.id IN ({$oph})
                  AND COALESCE(o.order_status, '') NOT IN ({$statusList})
                  {$extra}
            ", array_merge($oids, $extraBindings));
            $countingIds = array_flip(array_map(fn($r) => (int) $r->id, $counting));

            foreach ($orderRows as $o) {
                $orders[] = [
                    'id' => (int) $o->id,
                    'order_number' => $o->order_number,
                    'order_date' => $o->order_date,
                    'total_price' => (float) $o->total_price,
                    'status' => $o->order_status,
                    'channel' => $o->order_source_channel,
                    'categories' => array_keys($catsByOrder[(int) $o->id] ?? []),
                    'counts' => isset($countingIds[(int) $o->id]),
                    'hours_after' => round($sentAt->diffInMinutes(\Carbon\Carbon::parse($o->order_date)) / 60, 1),
                ];
            }
        }

        // Summary — the same definitions engagementMap() uses.
        // Only messages AFTER the send count (the list starts 5 minutes early
        // purely so the campaign message itself is visible).
        $sentStr = (string) $cc->sent_at;
        $first = fn(callable $f) => collect($messages)->first(fn($m) => $m['at'] > $sentStr && $f($m))['at'] ?? null;
        $tappedAt  = $first(fn($m) => $m['kind'] === 'tap');
        $repliedAt = $first(fn($m) => in_array($m['kind'], ['wrote', 'reaction'], true));
        $wroteAt   = $first(fn($m) => $m['kind'] === 'wrote');
        $teamAfter = $wroteAt ? $first(fn($m) => $m['kind'] === 'team' && $m['at'] > $wroteAt) : null;
        $firstCounting = collect($orders)->first(fn($o) => $o['counts']);

        $base['messages'] = $messages;
        $base['orders']   = $orders;
        $base['summary']  = [
            'tapped_at'       => $tappedAt,
            'replied_at'      => $repliedAt,
            'wrote_at'        => $wroteAt,
            'team_replied_at' => $teamAfter,
            'response_minutes'=> ($wroteAt && $teamAfter) ? \Carbon\Carbon::parse($wroteAt)->diffInMinutes(\Carbon\Carbon::parse($teamAfter)) : null,
            'conversation'    => (bool) $teamAfter,
            'waiting'         => $wroteAt && !$teamAfter,
            'first_order_at'  => $firstCounting['order_date'] ?? null,
            'hours_to_order'  => $firstCounting['hours_after'] ?? null,
            'window_ends_at'  => $until->toDateTimeString(),
            'messages_capped' => count($messages) >= 150,
        ];

        return $base;
    }

    /**
     * The Excluded/Skipped split lives in the error_message prefix, not the
     * status column — see CampaignWebController::create(). Kept here so every
     * caller applies it identically.
     */
    public function applyStatusFilter($query, string $statusFilter): void
    {
        if ($statusFilter === 'excluded') {
            $query->where('cc.status', 'skipped')->where('cc.error_message', 'LIKE', 'Excluded:%');
        } elseif ($statusFilter === 'skipped') {
            $query->where('cc.status', 'skipped')->where(function ($q) {
                $q->whereNull('cc.error_message')->orWhere('cc.error_message', 'NOT LIKE', 'Excluded:%');
            });
        } elseif ($statusFilter === 'undelivered') {
            $query->where('cc.status', 'sent')->whereNotNull('cc.undelivered_at');
        } elseif ($statusFilter === 'delivered') {
            // Same rule as counts(): a read receipt proves delivery too.
            $query->where('cc.status', 'sent')->where(function ($q) {
                $q->whereNotNull('cc.delivered_at')->orWhereNotNull('cc.read_at');
            });
        } elseif ($statusFilter === 'read') {
            $query->where('cc.status', 'sent')->whereNotNull('cc.read_at');
        } elseif ($statusFilter === 'replied') {
            $query->where('cc.status', 'sent')->whereNotNull('cc.replied_at');
        } elseif ($statusFilter === 'pending') {
            // 'sending' rows are mid-flight; showing them under Pending keeps
            // the operator's mental model simple (they are not done yet).
            $query->whereIn('cc.status', ['pending', 'sending']);
        } elseif ($statusFilter !== 'all') {
            $query->where('cc.status', $statusFilter);
        }
    }

    /** The count block every campaign endpoint returns. */
    public function counts(int $campaignId): ?object
    {
        return DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status IN ('pending','sending') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='sending' THEN 1 ELSE 0 END) as sending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded,
                SUM(CASE WHEN status='sent' AND (delivered_at IS NOT NULL OR read_at IS NOT NULL) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status='sent' AND read_at IS NOT NULL THEN 1 ELSE 0 END) as `read`,
                SUM(CASE WHEN status='sent' AND replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
                SUM(CASE WHEN status='sent' AND undelivered_at IS NOT NULL THEN 1 ELSE 0 END) as undelivered
            ")
            ->first();
    }
}
