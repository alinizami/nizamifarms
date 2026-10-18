<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\CampaignFilterService;
use App\Services\Campaigns\CampaignAccess;
use App\Services\Campaigns\CampaignSendService;
use App\Services\Campaigns\CampaignStatsService;
use App\Models\CRM\CustomerModel;
use Carbon\Carbon;

class CampaignWebController extends Controller
{
    protected CampaignFilterService $filterService;
    protected CampaignSendService $sendService;
    protected CampaignStatsService $statsService;
    protected CampaignAccess $access;

    public function __construct(
        CampaignFilterService $filterService,
        CampaignSendService $sendService,
        CampaignStatsService $statsService,
        CampaignAccess $access
    ) {
        $this->filterService = $filterService;
        $this->sendService   = $sendService;
        $this->statsService  = $statsService;
        $this->access        = $access;
    }

    /**
     * Campaigns can start thousands of WhatsApp messages and spend real money,
     * so the web side now enforces the same permission the mobile app always
     * has (`view_campaigns`). Until Jul-2026 any signed-in web user could open
     * the page and send — the sidebar link was hidden, but the routes were not
     * protected. Mirrors the sidebar's own condition, Taimur role included.
     */
    protected function denyIfNotPermitted()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Not signed in'], 401);
        }

        // Same conditions the sidebar uses to show the Campaigns link, so a
        // visible menu item can never lead to a 403 (or vice-versa).
        if ($this->access->canView($user)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to view campaigns.',
        ], 403);
    }

    /**
     * Gate for anything that CHANGES a campaign — create, add customers, send,
     * pause, refresh dedup, skip, end.
     *
     * Separating write from read (Jul-2026) is what lets a view-only analyst
     * account be given campaign-running rights without being handed write access
     * to the rest of the operational system. `ReadOnlyGuard` also checks
     * `manage_campaigns` before it will let a view-only user's POST through, so
     * the two layers agree.
     *
     * Backwards-compatible on purpose: if `manage_campaigns` has not been seeded
     * yet (code uploaded before the SQL), everyone who can view can still send —
     * exactly today's behaviour. Otherwise a deploy in the wrong order would
     * silently stop all campaign sending.
     */
    protected function denyIfCannotManage()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        if ($this->access->canManage(Auth::user())) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Your account can view campaigns but not send or change them.',
        ], 403);
    }

    public function index()
    {
        return view('pages.campaigns.index');
    }

    // =====================================================================
    // Lists / pickers
    // =====================================================================

    public function getCampaigns()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $campaigns = DB::table('t_crm_campaigns')
            // Display name so cards can show "eid bookings" rather than the raw
            // Cloud API key. LEFT JOIN so a campaign whose template was deleted
            // still lists.
            ->leftJoin('t_wa_templates as tpl', 'tpl.name', '=', 't_crm_campaigns.wa_template_name')
            ->select(
                't_crm_campaigns.*',
                'tpl.display_name as template_display_name',
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status IN ('pending','sending')) as pending_count"),
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%')) as skipped_count"),
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'skipped' AND error_message LIKE 'Excluded:%') as excluded_count"),
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'failed') as failed_count"),
                // When this campaign last actually messaged someone — lets the
                // card distinguish live work from a forgotten campaign.
                DB::raw("(SELECT MAX(sent_at) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'sent') as last_sent_at")
            )
            ->orderByRaw("FIELD(t_crm_campaigns.status, 'active', 'ended')")
            ->orderBy('t_crm_campaigns.created_at', 'desc')
            ->get();

        return response()->json([
            'success'   => true,
            'campaigns' => $campaigns,
            'quota'     => $this->quotaPayload(),
            // Cards dim a campaign that hasn't sent in this long; the threshold
            // lives on the server so the list and the overview agree.
            'stale_days' => CampaignStatsService::STALE_DAYS,
        ]);
    }

    /**
     * The campaigns landing view. Answers "how is my messaging doing, and does
     * anything need me?" — today's WhatsApp allowance, anything sending right
     * now, per-template results, and a needs-attention list.
     *
     * Template results are reachable from here in ONE click; before this they
     * were two clicks deep (open a campaign, then By Template).
     */
    public function overview()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        return response()->json([
            'success'  => true,
            'overview' => $this->statsService->overview(),
            'quota'    => $this->quotaPayload(),
        ]);
    }

    public function getTemplates()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $templates = DB::table('t_wa_templates')
            ->where('status', 'approved')
            ->orderBy('display_name')
            ->get();

        return response()->json(['success' => true, 'templates' => $templates]);
    }

    public function getCities()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $cities = DB::table('t_crm_prod_customer')
            ->whereNull('merged_into_customer_id')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city', DB::raw('COUNT(*) as count'))
            ->groupBy('city')
            ->orderBy('count', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'cities' => $cities]);
    }

    /**
     * Distinct years that have at least one Qurbani order, combining the
     * current orders table and the history table (same definition used
     * by the Qurbani Dashboard so filter results line up with what the
     * user sees there).
     */
    public function getQurbaniYears()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $years = collect();

        // Years from current orders (qurbani_day set OR line item with attribute_1=qurbani)
        $current = DB::table('t_crm_prod_order as o')
            ->where(function ($q) {
                $q->whereNotNull('o.qurbani_day')
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as li')
                          ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                          ->whereColumn('li.order_id', 'o.id')
                          ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                  });
            })
            ->select(DB::raw('DISTINCT YEAR(o.order_date) as year'))
            ->pluck('year');
        $years = $years->merge($current);

        // Years from history table (name/sku match), if the table exists
        if (Schema::hasTable('t_crm_history_order') && Schema::hasTable('t_crm_history_order_line_item')) {
            $hist = DB::table('t_crm_history_order as ho')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_history_order_line_item as hli')
                        ->whereColumn('hli.order_id', 'ho.id')
                        ->where(function ($q) {
                            $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                              ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                              ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                        });
                })
                ->select(DB::raw('DISTINCT YEAR(ho.order_date) as year'))
                ->pluck('year');
            $years = $years->merge($hist);
        }

        $years = $years->filter()->unique()->sortDesc()->values();

        return response()->json(['success' => true, 'years' => $years]);
    }

    /** Products for the "track specific products" campaign type. */
    public function getProducts(Request $request)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $search = trim((string) $request->get('q', ''));

        // Active products only (the picker used to list retired ones too),
        // each labelled with its category so "Frozen" items are recognisable.
        $q = DB::table('t_crm_prod_product')
            ->where('is_active', 1)
            ->select('id', 'title', 'attribute_1', 'product_type')
            ->orderBy('title');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'LIKE', "%{$search}%")->orWhere('product_type', 'LIKE', "%{$search}%");
            });
        }

        $rows = $q->limit(300)->get();
        foreach ($rows as $r) {
            $r->category = \App\Services\CRM\ProductCategoryRollup::rollup($r->product_type);
        }

        return response()->json(['success' => true, 'products' => $rows]);
    }

    /** Categories that can be tracked, with how many active products each has. */
    public function getCategories()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        return response()->json([
            'success' => true,
            'categories' => \App\Services\CRM\ProductCategoryRollup::selectable(),
            'available' => Schema::hasColumn('t_crm_campaigns', 'tracked_categories'),
        ]);
    }

    // =====================================================================
    // Preview / create / extend
    // =====================================================================

    public function preview(Request $request)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);

        // Optional template-dedup preview. When the user has already picked
        // a template in the Create Campaign modal, the frontend passes it
        // here so we can show "100 of 500 matched already received this
        // template in the last N days and will be skipped" before they
        // actually hit "Create". Nothing is written here — this is purely
        // informational and the dedup is applied again (authoritatively)
        // inside create()/addCustomers().
        $template   = trim((string) $request->input('wa_template_name', ''));
        $windowDays = (int) $request->input('dedup_window_days', 0);
        $alreadySentCount = 0;
        $alreadySentIds   = [];
        if ($template !== '' && $windowDays > 0 && !empty($result['customer_ids'])) {
            $alreadySentIds = $this->filterService->customersRecentlySentTemplate(
                $result['customer_ids'],
                $template,
                $windowDays
            );
            $alreadySentCount = count($alreadySentIds);
        }

        $netToSend = max(0, count($result['customer_ids']) - $alreadySentCount);

        return response()->json([
            'success' => true,
            'count' => count($result['customer_ids']),
            'group_counts' => $result['group_counts'],
            'excluded_count' => $result['excluded_count'],
            'already_sent_count' => $alreadySentCount,
            // Net customers that will actually be queued as 'pending' if
            // the campaign is created/extended right now.
            'net_to_send' => $netToSend,
            'dedup_window_days' => $windowDays,
            'wa_template_name' => $template ?: null,
            // Everything the send dialog needs to set expectations up front:
            // how many sessions this will take at the proposed batch size, and
            // whether today's WhatsApp allowance can even cover one.
            'quota' => $this->quotaPayload(),
            'no_phone_count' => $this->countWithoutPhone($result['customer_ids']),
        ]);
    }

    /**
     * Add more customers to an existing active campaign from a filter payload.
     * Dedups against customers already in the campaign — only truly new
     * customers are inserted as 'pending'. Returns the number actually added,
     * plus the updated counts.
     */
    public function addCustomers(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }
        if ($campaign->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Cannot add customers to an ended campaign'], 422);
        }

        $request->validate([
            'filters' => 'required|array',
            'dedup_window_days' => 'nullable|integer|min:0|max:365',
        ]);

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);
        $candidateIds = $result['customer_ids'];
        $tagsById = $result['tags_by_id'] ?? [];

        if (empty($candidateIds)) {
            return response()->json([
                'success' => true,
                'added' => 0,
                'already_in_campaign' => 0,
                'matched' => 0,
                'excluded_count' => $result['excluded_count'],
                'group_counts' => $result['group_counts'],
            ]);
        }

        // Dedup against customers already in this campaign (any status).
        $existingIds = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->pluck('customer_id')
            ->map(fn($v) => (int)$v)
            ->all();
        $existingSet = array_flip($existingIds);

        $toInsert = [];
        foreach ($candidateIds as $cId) {
            if (!isset($existingSet[$cId])) {
                $toInsert[] = $cId;
            }
        }

        // Template dedup for "Add more customers" — same rule as create():
        // new candidates who already received this campaign's template
        // in the window are inserted as status='skipped' with the
        // 'Excluded:' error_message prefix so they land in the Excluded
        // tab rather than Pending. Operator-Skipped stays separate.
        $windowDays = (int) $request->input('dedup_window_days', 0);
        $templateName = (string) ($campaign->wa_template_name ?: '');
        $alreadySentIds = $this->filterService->customersRecentlySentTemplate($toInsert, $templateName, $windowDays);
        $alreadySentSet = array_flip($alreadySentIds);
        $excludedByDedup = count($alreadySentIds);
        $excludedReason = ($windowDays > 0 && $templateName !== '')
            ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
            : 'Excluded: recent template send';

        if (!empty($toInsert)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $hasErrColumn  = Schema::hasColumn('t_crm_campaign_customers', 'error_message');
            // See create() — keep the row shape uniform or MySQL throws
            // 21S01 on the first row whose key-set differs from row 0.
            $rows = array_map(function ($cId) use ($id, $tagsById, $hasTagsColumn, $hasErrColumn, $alreadySentSet, $excludedReason) {
                $isExcluded = isset($alreadySentSet[(int) $cId]);
                $row = [
                    'campaign_id' => (int) $id,
                    'customer_id' => $cId,
                    'status' => $isExcluded ? 'skipped' : 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
                }
                if ($hasErrColumn) {
                    $row['error_message'] = $isExcluded ? $excludedReason : null;
                }
                return $row;
            }, $toInsert);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('t_crm_campaign_customers')->insert($chunk);
            }

            DB::table('t_crm_campaigns')
                ->where('id', $id)
                ->increment('total_customers', count($toInsert), ['updated_at' => now()]);
        }

        return response()->json([
            'success' => true,
            // "added" now means rows actually inserted into the campaign
            // (pending + newly-excluded). The split lives in the two
            // fields below.
            'added' => count($toInsert),
            'added_pending' => count($toInsert) - $excludedByDedup,
            'already_in_campaign' => count($candidateIds) - count($toInsert),
            'matched' => count($candidateIds),
            'excluded_count' => $result['excluded_count'],
            'group_counts' => $result['group_counts'],
            'counts' => $this->statsService->counts((int) $id),
            'excluded_by_dedup' => $excludedByDedup,
            'dedup_window_days' => $windowDays,
        ]);
    }

    public function create(Request $request)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $request->validate([
            'name' => 'required|string|max:255',
            'wa_template_name' => 'required|string|max:255',
            'filters' => 'required|array',
            'dedup_window_days' => 'nullable|integer|min:0|max:365',
            // 'general' = any order counts; 'products' = only orders containing
            // the tracked products; 'app_orders' = only orders placed through
            // the customer mobile app (the app-install campaign).
            // 'category' (Oct-2026) = only orders containing a product of the
            // picked category, e.g. Frozen.
            'tracking_type' => 'nullable|in:general,products,app_orders,category',
            'tracking_window_days' => 'nullable|integer|min:1|max:365',
            'session_limit' => 'nullable|integer|min:1|max:100000',
            'tracked_product_ids' => 'nullable|array',
            'tracked_product_ids.*' => 'integer',
            'tracked_categories' => 'nullable|array',
            'tracked_categories.*' => 'string|max:60',
        ]);

        $tracking = $this->trackingColumns($request);
        if (isset($tracking['error'])) {
            return response()->json(['success' => false, 'message' => $tracking['error']], 422);
        }

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);
        $customerIds = $result['customer_ids'];
        $tagsById = $result['tags_by_id'] ?? [];

        // Template dedup — optional but defaulted to 30 days in the UI.
        // Customers who already received this exact template in the
        // window are inserted as status='skipped' with a distinguishing
        // error_message prefix ('Excluded:'). The campaign detail view
        // surfaces them under a dedicated **Excluded** tab so operators
        // can still audit "who matched my filter but got skipped" —
        // without them polluting Pending or the operator-Skipped tab.
        $templateName = (string) $request->input('wa_template_name');
        $windowDays   = (int) $request->input('dedup_window_days', 0);
        $alreadySentIds = $this->filterService->customersRecentlySentTemplate($customerIds, $templateName, $windowDays);
        $alreadySentSet = array_flip($alreadySentIds);
        $excludedByDedup = count($alreadySentIds);

        // The "Excluded:" prefix is the contract between insert and
        // detail(): anything else in skipped is treated as operator-
        // skipped. Keep this string stable unless you update both sides.
        $excludedReason = ($windowDays > 0 && $templateName !== '')
            ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
            : 'Excluded: recent template send';

        $campaignId = DB::table('t_crm_campaigns')->insertGetId($tracking + [
            'name' => $request->input('name'),
            'status' => 'active',
            'send_state' => 'idle',
            'send_mode' => 'manual',
            'filters_json' => json_encode($filters),
            'message_template' => $request->input('notes', ''),
            'wa_template_name' => $templateName,
            'wa_template_language' => $request->input('wa_template_language', 'en'),
            // tracking_type / tracked_product_ids / tracked_categories come
            // from trackingColumns() above.
            'tracking_window_days' => $request->input('tracking_window_days', 30),
            // Persisting the dedup window lets the sender and the
            // "Refresh Dedup" action re-check later — the operator's
            // original intent follows the campaign for its whole life.
            // 0 disables the guard entirely (same as today's opt-out).
            'dedup_window_days' => $windowDays,
            // Remembered per campaign so the send dialog proposes the same
            // batch size next session instead of making them retype it.
            'session_limit' => (int) $request->input('session_limit', $this->sendService->defaultSessionLimit()),
            // total_customers reflects every row this campaign knows
            // about (Pending + Excluded). That way the stats on the
            // campaigns list still match what you see inside.
            'total_customers' => count($customerIds),
            'sent_count' => 0,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!empty($customerIds)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $hasErrColumn  = Schema::hasColumn('t_crm_campaign_customers', 'error_message');
            // Every row in a batch insert MUST carry the same set of
            // keys — Laravel emits one column list built from the first
            // row. Mixing rows that have 'error_message' with rows that
            // don't triggers MySQL 21S01 on the first excluded row. So
            // we always include the key (null when not excluded) when
            // the column exists.
            $rows = array_map(function ($cId) use ($campaignId, $tagsById, $hasTagsColumn, $hasErrColumn, $alreadySentSet, $excludedReason) {
                $isExcluded = isset($alreadySentSet[(int) $cId]);
                $row = [
                    'campaign_id' => $campaignId,
                    'customer_id' => $cId,
                    'status' => $isExcluded ? 'skipped' : 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
                }
                if ($hasErrColumn) {
                    $row['error_message'] = $isExcluded ? $excludedReason : null;
                }
                return $row;
            }, $customerIds);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('t_crm_campaign_customers')->insert($chunk);
            }
        }

        return response()->json([
            'success' => true,
            'campaign_id' => $campaignId,
            // Full campaign size = pending + excluded. Matches the list-
            // view badge and the detail 'Total' stat.
            'total_customers' => count($customerIds),
            'pending_count' => count($customerIds) - $excludedByDedup,
            'excluded_by_dedup' => $excludedByDedup,
            'matched' => count($customerIds),
            'dedup_window_days' => $windowDays,
        ]);
    }

    // =====================================================================
    // Detail
    // =====================================================================

    public function detail(Request $request, $id)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        // Same friendly template label the campaign cards show, so the header
        // and the list can't disagree about what this campaign sends.
        $campaign->template_display_name = $campaign->wa_template_name
            ? $this->filterService->lookupTemplateDisplayName($campaign->wa_template_name)
            : null;

        $statusFilter = $request->get('status', 'pending');
        $page    = max(1, (int) $request->get('page', 1));
        $perPage = min(500, max(10, (int) $request->get('per_page', 100)));

        // Paginated: the old endpoint returned EVERY row, so opening a
        // 5,700-recipient campaign shipped the whole list to the browser.
        // sort_by/sort_dir let the operator re-order the list (highest spend /
        // most orders / recency) without recreating the campaign; absent, the
        // campaign's stored send order applies. Junk values are normalised
        // inside customerRows, so passing them straight through is safe.
        $rows = $this->statsService->customerRows(
            (int) $id, $statusFilter, $page, $perPage,
            $request->get('sort_by'), $request->get('sort_dir')
        );

        return response()->json([
            'success'      => true,
            'campaign'     => $campaign,
            'customers'    => $rows['customers'],
            'pagination'   => [
                'page' => $rows['page'], 'per_page' => $rows['per_page'],
                'total' => $rows['total'], 'pages' => $rows['pages'],
            ],
            'sort'         => ['by' => $rows['sort_by'], 'dir' => $rows['sort_dir']],
            'counts'       => $this->statsService->counts((int) $id),
            'eligible'     => $this->sendService->eligibleCount((int) $id),
            'quota'        => $this->quotaPayload(),
            'session_limit'=> (int) ($campaign->session_limit ?: $this->sendService->defaultSessionLimit()),
            'run'          => $campaign->active_run_id ? $this->sendService->runProgress((int) $campaign->active_run_id) : null,
            'runs'         => $this->statsService->runHistory((int) $id, 10),
        ]);
    }

    /**
     * Persist a new send order for this campaign.
     *
     * The sort chosen at creation was frozen into filters_json with no way to
     * change it afterwards. This updates it, which matters far beyond the list
     * view: `CampaignSendService::pickCandidates()` and the background worker
     * both read the STORED sort — so after this, "Send Messages → first 100"
     * genuinely means the top 100 by the chosen order (e.g. highest lifetime
     * spend), not the top 100 of an order picked weeks ago.
     *
     * Write-gated: changing who gets messaged first is a campaign mutation.
     * Viewers without manage rights can still pass sort params to detail() for
     * a re-ordered VIEW — that changes nothing.
     */
    public function setSort(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        [$sortBy, $sortDir] = $this->filterService->normalizeSort([
            'sort_by'  => (string) $request->input('sort_by'),
            'sort_dir' => (string) $request->input('sort_dir'),
        ]);

        $filters = json_decode($campaign->filters_json ?? '{}', true) ?: [];
        $filters['sort_by']  = $sortBy;
        $filters['sort_dir'] = $sortDir;

        DB::table('t_crm_campaigns')->where('id', $id)->update([
            'filters_json' => json_encode($filters),
            'updated_at'   => now(),
        ]);

        return response()->json([
            'success' => true,
            'sort'    => ['by' => $sortBy, 'dir' => $sortDir],
        ]);
    }

    // =====================================================================
    // Sending
    // =====================================================================

    /**
     * The one send endpoint. Called repeatedly by the browser for a foreground
     * send: each call does a bounded slice of work and reports progress, and the
     * client keeps calling while stop_reason is 'time_budget'.
     *
     * Session accounting lives on the run row, so passing run_id back means the
     * "first 100" the operator asked for stays 100 no matter how many HTTP calls
     * it takes to get through them.
     */
    public function send(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $request->validate([
            'limit'          => 'nullable|integer|min:1|max:100000',
            'customer_ids'   => 'nullable|array',
            'customer_ids.*' => 'integer',
            'include_failed' => 'nullable|boolean',
            'run_id'         => 'nullable|integer',
            'body_params'    => 'nullable|array',
        ]);

        // `limit` is how the operator caps a session. It is optional only so the
        // legacy /send-bulk alias (an old browser tab mid-send) still behaves:
        // there we fall back to the explicit selection size, then the campaign's
        // remembered session limit. A send with no cap at all never happens.
        $customerIds = $request->input('customer_ids');
        $limit = (int) $request->input('limit', 0);
        if ($limit <= 0) {
            $limit = is_array($customerIds) && !empty($customerIds)
                ? count($customerIds)
                : (int) (DB::table('t_crm_campaigns')->where('id', $id)->value('session_limit')
                         ?: $this->sendService->defaultSessionLimit());
        }

        $result = $this->sendService->sendBatch((int) $id, [
            'limit'          => $limit,
            'customer_ids'   => $customerIds,
            'include_failed' => (bool) $request->input('include_failed', false),
            'mode'           => 'manual',
            'user_id'        => Auth::id(),
            'run_id'         => $request->input('run_id'),
            'body_params'    => $request->input('body_params'),
        ]);

        if (!($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'busy'    => $result['busy'] ?? false,
                'message' => $result['message'] ?? 'Send failed',
            ], ($result['busy'] ?? false) ? 409 : 422);
        }

        // A foreground session that has finished should not leave the campaign
        // looking like it is mid-run.
        if (($result['stop_reason'] ?? null) !== 'time_budget' && !empty($result['run_id'])) {
            $this->sendService->finishRun((int) $result['run_id'], (string) $result['stop_reason']);
            DB::table('t_crm_campaigns')->where('id', $id)->update([
                'active_run_id' => null,
                'send_paused_reason' => $result['message'] ?? null,
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success'  => true,
            'results'  => [
                'sent' => $result['sent'], 'failed' => $result['failed'],
                'excluded' => $result['excluded'], 'attempted' => $result['attempted'],
                'errors' => $result['errors'],
            ],
            'run_id'      => $result['run_id'] ?? null,
            'stop_reason' => $result['stop_reason'] ?? null,
            'message'     => $result['message'] ?? null,
            'remaining'   => $result['remaining'] ?? null,
            'quota'       => $result['quota'] ?? $this->quotaPayload(),
            'counts'      => $this->statsService->counts((int) $id),
            'run'         => !empty($result['run_id']) ? $this->sendService->runProgress((int) $result['run_id']) : null,
        ]);
    }

    /**
     * Hand this campaign to the background worker. The operator can close the
     * browser; campaigns:send-process finishes the session on the schedule.
     */
    public function sendBackground(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $request->validate([
            'limit'          => 'required|integer|min:1|max:100000',
            'customer_ids'   => 'nullable|array',
            'customer_ids.*' => 'integer',
        ]);

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->where('status', 'active')->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found or ended'], 404);
        }
        if (!$campaign->wa_template_name) {
            return response()->json(['success' => false, 'message' => 'No WhatsApp template configured'], 422);
        }
        if ($campaign->send_state === 'running') {
            return response()->json(['success' => false, 'message' => 'This campaign is already sending in the background.'], 409);
        }

        // ---- Optional hand-picked audience -------------------------------
        // The selection rides on the run row (selection_json), so the worker
        // messages exactly who the operator ticked — nobody else. Without the
        // column we refuse rather than silently fall back to the whole
        // campaign: sending to the WRONG people is the one failure this
        // feature exists to prevent.
        $selection = null;
        $requested = (array) $request->input('customer_ids', []);
        if (!empty($requested)) {
            if (!$this->sendService->supportsRunSelection()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Background sending to a hand-picked selection needs a small database update that has not been run yet '
                        . '(selection_json on t_crm_campaign_send_runs). Use "Send now" to message your selection, or run the pending SQL first.',
                ], 422);
            }
            $selection = array_values(array_unique(array_map('intval', $requested)));
            $eligible = (int) DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->whereIn('customer_id', $selection)
                ->where('status', 'pending')
                ->count();
            if ($eligible <= 0) {
                return response()->json(['success' => false, 'message' => 'No one in your selection is still Pending.'], 422);
            }
        } else {
            $eligible = $this->sendService->eligibleCount((int) $id);
            if ($eligible <= 0) {
                return response()->json(['success' => false, 'message' => 'No one left to send to.'], 422);
            }
        }

        $limit = min((int) $request->input('limit'), $eligible);
        $runId = $this->sendService->startRun((int) $id, $limit, 'background', Auth::id(), $selection);

        $campaignUpdate = [
            'send_state'         => 'running',
            'send_mode'          => 'background',
            'active_run_id'      => $runId,
            'send_paused_reason' => null,
            'updated_at'         => now(),
        ];
        // The remembered batch size steers the dialog prefill for ordinary
        // ranking sends. A hand-picked selection is a one-off — remembering
        // its size would puzzle the next session (it did: 73 selected, 61
        // offered, read as "re-sending the old 61").
        if ($selection === null) {
            $campaignUpdate['session_limit'] = $limit;
        }
        DB::table('t_crm_campaigns')->where('id', $id)->update($campaignUpdate);

        Log::info('Campaign background send started', [
            'campaign_id' => $id, 'run_id' => $runId, 'target' => $limit,
            'user_id' => Auth::id(), 'selection' => $selection ? count($selection) : null,
        ]);

        return response()->json([
            'success' => true,
            'run_id'  => $runId,
            'target'  => $limit,
            'message' => "Started in the background — {$limit} message" . ($limit === 1 ? '' : 's')
                . ($selection !== null ? " will go to your selected customers" : " will go out")
                . " over the next few minutes. You can close this page.",
            'background_enabled' => $this->sendService->backgroundEnabled(),
        ]);
    }

    /** Stop a background run (recipients stay Pending). */
    public function sendPause(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        if ($campaign->active_run_id) {
            $this->sendService->finishRun((int) $campaign->active_run_id, 'operator_paused');
        }

        DB::table('t_crm_campaigns')->where('id', $id)->update([
            'send_state'         => 'idle',
            'active_run_id'      => null,
            'send_paused_reason' => 'Paused by you — nobody else was messaged.',
            'updated_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paused. Everyone not yet messaged is still Pending.',
            'counts'  => $this->statsService->counts((int) $id),
        ]);
    }

    /**
     * Put never-delivered recipients back into Pending so they can be sent
     * again. Meta's marketing frequency cap ("healthy ecosystem engagement",
     * error 131049) drops some deliveries by recipient-side policy — the
     * customer never received the message, so a later resend is a retry, not
     * a repeat. Dedup already agrees: the delivery-failure webhook writes
     * t_wa_messages.status='failed', which customersRecentlySentTemplate()
     * excludes, so a requeued row is not dedup-skipped on the next send.
     *
     * Scope: rows with status='sent' AND undelivered_at set (send accepted,
     * receipt came back failed). Optional customer_ids narrows to the
     * per-row button; without it, the whole Undelivered list is requeued.
     */
    public function requeueUndelivered(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $request->validate([
            'customer_ids'   => 'nullable|array',
            'customer_ids.*' => 'integer',
        ]);

        $q = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->where('status', 'sent')
            ->whereNotNull('undelivered_at');

        $requested = (array) $request->input('customer_ids', []);
        if (!empty($requested)) {
            $q->whereIn('customer_id', array_map('intval', $requested));
        }

        // Back to a clean Pending row. The old attempt's story stays in
        // t_wa_messages; clearing wa_message_id stops a late receipt for the
        // OLD message from stamping the NEW attempt.
        $update = [
            'status'         => 'pending',
            'claimed_at'     => null,
            'sent_at'        => null,
            'sent_by'        => null,
            'delivered_at'   => null,
            'read_at'        => null,
            'undelivered_at' => null,
            'error_message'  => null,
        ];
        if (Schema::hasColumn('t_crm_campaign_customers', 'wa_message_id')) {
            $update['wa_message_id'] = null;
        }
        if (Schema::hasColumn('t_crm_campaign_customers', 'sent_via')) {
            $update['sent_via'] = null;
        }

        $n = $q->update($update);

        if ($n > 0) {
            // The denormalised sent counter just shrank.
            DB::table('t_crm_campaigns')->where('id', $id)->update([
                'sent_count' => DB::raw('(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = ' . (int) $id . " AND status = 'sent')"),
                'updated_at' => now(),
            ]);
        }

        Log::info('Campaign requeued undelivered', [
            'campaign_id' => $id, 'rows' => $n, 'user_id' => Auth::id(),
        ]);

        return response()->json([
            'success'  => true,
            'requeued' => $n,
            'message'  => $n > 0
                ? "{$n} moved back to Pending. Tip: waiting a day or two before resending improves the odds WhatsApp delivers them this time."
                : 'Nothing to requeue.',
            'counts'   => $this->statsService->counts((int) $id),
        ]);
    }

    /** Live progress for a background run — polled by the detail panel. */
    public function sendStatus(Request $request, $id)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        return response()->json([
            'success'       => true,
            'send_state'    => $campaign->send_state,
            'send_mode'     => $campaign->send_mode,
            'paused_reason' => $campaign->send_paused_reason,
            'run'           => $campaign->active_run_id ? $this->sendService->runProgress((int) $campaign->active_run_id) : null,
            'counts'        => $this->statsService->counts((int) $id),
            'eligible'      => $this->sendService->eligibleCount((int) $id),
            'quota'         => $this->quotaPayload(),
        ]);
    }

    public function quota()
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;
        return response()->json(['success' => true, 'quota' => $this->quotaPayload()]);
    }

    // =====================================================================
    // Dedup / lifecycle
    // =====================================================================

    /**
     * Manual "Refresh Dedup" action. Re-evaluates every pending row in
     * this campaign against the current WhatsApp send history using the
     * campaign's stored dedup_window_days + wa_template_name, and moves
     * newly-matching rows into status='skipped' with the 'Excluded:'
     * prefix so they surface in the Excluded tab.
     *
     * Idempotent + safe on campaigns without dedup configured
     * (window=0 or no template) — returns moved=0 and a hint message.
     */
    public function refreshDedup(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $templateName = (string) ($campaign->wa_template_name ?? '');
        $windowDays   = (int) ($campaign->dedup_window_days ?? 0);

        if ($windowDays <= 0 || $templateName === '') {
            return response()->json([
                'success' => true,
                'moved'   => 0,
                'message' => 'Dedup is not enabled for this campaign (window or template not set).',
                'dedup_window_days' => $windowDays,
                'wa_template_name'  => $templateName ?: null,
            ]);
        }

        // Only consider still-pending rows. 'sent' is already done, 'failed'
        // keeps its retry option, 'skipped'/excluded are already resolved.
        $pendingIds = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->where('status', 'pending')
            ->pluck('customer_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $moved = 0;
        if (!empty($pendingIds)) {
            $nowExcludedIds = $this->filterService->customersRecentlySentTemplate(
                $pendingIds,
                $templateName,
                $windowDays
            );

            if (!empty($nowExcludedIds)) {
                $reason = 'Excluded: already received template "' . $templateName . '" in last '
                    . $windowDays . ' day' . ($windowDays === 1 ? '' : 's');

                $moved = DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)
                    ->whereIn('customer_id', $nowExcludedIds)
                    ->where('status', 'pending')
                    ->update(['status' => 'skipped', 'error_message' => $reason]);
            }
        }

        Log::info('Campaign dedup refresh', [
            'campaign_id' => $id,
            'template'    => $templateName,
            'window_days' => $windowDays,
            'pending_scanned' => count($pendingIds),
            'moved_to_excluded' => $moved,
        ]);

        return response()->json([
            'success' => true,
            'moved'   => $moved,
            'pending_scanned' => count($pendingIds),
            'dedup_window_days' => $windowDays,
            'wa_template_name'  => $templateName,
            'counts' => $this->statsService->counts((int) $id),
        ]);
    }

    public function end(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if ($campaign && $campaign->active_run_id) {
            $this->sendService->finishRun((int) $campaign->active_run_id, 'campaign_ended');
        }

        DB::table('t_crm_campaigns')->where('id', $id)->update([
            'status' => 'ended', 'ended_at' => now(), 'updated_at' => now(),
            // Ending a campaign must also stop the background worker, or it
            // would keep messaging people for a campaign the operator closed.
            'send_state' => 'idle', 'active_run_id' => null,
        ]);

        return response()->json(['success' => true]);
    }

    public function skip(Request $request, $id, $customerId)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        // Operator-skipped rows deliberately leave error_message NULL
        // so the campaign detail view's 'Excluded:' LIKE check excludes
        // them — they land in the Skipped tab, not the Excluded tab.
        DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)->where('customer_id', $customerId)
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'skipped', 'claimed_at' => null]);

        return response()->json(['success' => true, 'counts' => $this->statsService->counts((int) $id)]);
    }

    // =====================================================================
    // Results
    // =====================================================================

    public function stats(Request $request, $id)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $stats = $this->statsService->forCampaign((int) $id);
        if (!$stats) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        return response()->json(['success' => true, 'stats' => $stats]);
    }

    /**
     * The tile numbers that need orders or the chat log (Ordered, Tapped,
     * Conversation, Waiting on us). Separate from detail() on purpose: they
     * cost a few hundred ms on a big campaign, and detail() runs on every tab
     * switch. The page fetches these after the list is already on screen.
     * Cached 60s per campaign for the same reason.
     */
    public function engagement(Request $request, $id)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $key = 'campaign_engagement_' . (int) $id . '_' . md5(($campaign->tracking_type ?? '') . '|'
            . ($campaign->tracked_product_ids ?? '') . '|' . ($campaign->tracked_categories ?? '') . '|' . ($campaign->tracking_window_days ?? ''));

        $data = \Illuminate\Support\Facades\Cache::remember($key, 60, function () use ($id, $campaign) {
            $window = (int) ($campaign->tracking_window_days ?: 30);
            $conv = $this->statsService->conversions([(int) $id], $window, $campaign);
            return array_merge($this->statsService->engagementCounts((int) $id), [
                'ordered' => $conv['converters'],
                'orders'  => $conv['orders'],
                'revenue' => $conv['revenue'],
            ]);
        });

        return response()->json([
            'success' => true,
            'engagement' => $data,
            'tracking' => [
                'type' => $campaign->tracking_type ?: 'general',
                'categories' => $this->statsService->trackedCategories($campaign),
                'product_count' => count(json_decode($campaign->tracked_product_ids ?? '[]', true) ?: []),
                'window_days' => (int) ($campaign->tracking_window_days ?: 30),
            ],
        ]);
    }

    /** One customer's story after the message — receipts, chat, orders. */
    public function timeline(Request $request, $id, $customerId)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $data = $this->statsService->customerTimeline((int) $id, (int) $customerId);
        if (!$data) {
            return response()->json(['success' => false, 'message' => 'Not found in this campaign'], 404);
        }
        return response()->json(['success' => true, 'timeline' => $data]);
    }

    /**
     * Change what counts as "Ordered" on an EXISTING campaign — until Oct-2026
     * this was fixed at creation, so a frozen campaign created as "any order"
     * could only ever report mutton orders as frozen wins. Only the counting
     * rule changes: nobody is messaged, added or removed.
     */
    public function updateTracking(Request $request, $id)
    {
        if ($deny = $this->denyIfCannotManage()) return $deny;

        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $request->validate([
            'tracking_type' => 'required|in:general,products,app_orders,category',
            'tracking_window_days' => 'nullable|integer|min:1|max:365',
            'tracked_product_ids' => 'nullable|array',
            'tracked_product_ids.*' => 'integer',
            'tracked_categories' => 'nullable|array',
            'tracked_categories.*' => 'string|max:60',
        ]);

        $update = $this->trackingColumns($request);
        if (isset($update['error'])) {
            return response()->json(['success' => false, 'message' => $update['error']], 422);
        }
        if ($request->filled('tracking_window_days')) {
            $update['tracking_window_days'] = (int) $request->input('tracking_window_days');
        }
        $update['updated_at'] = now();

        DB::table('t_crm_campaigns')->where('id', $id)->update($update);

        // Full results only when the Results window is open to show them —
        // they take a few seconds on a big campaign.
        return response()->json([
            'success' => true,
            'stats' => $request->boolean('with_stats') ? $this->statsService->forCampaign((int) $id) : null,
        ]);
    }

    /**
     * tracking_type + its product/category list, validated the same way for
     * create() and updateTracking(). Returns ['error' => msg] when the request
     * can't be honoured.
     */
    protected function trackingColumns(Request $request): array
    {
        $type = (string) ($request->input('tracking_type') ?: 'general');
        $products = array_values(array_filter(array_map('intval', (array) $request->input('tracked_product_ids', []))));
        $categories = array_values(array_unique(array_filter(array_map(
            fn($c) => trim((string) $c), (array) $request->input('tracked_categories', [])
        ))));
        $hasCatCol = Schema::hasColumn('t_crm_campaigns', 'tracked_categories');

        if ($type === 'category') {
            if (!$hasCatCol) {
                return ['error' => 'Category tracking needs the Oct-2026 database update first (tracked_categories column).'];
            }
            if (empty($categories)) {
                return ['error' => 'Pick at least one category to track.'];
            }
        }
        if ($type === 'products' && empty($products)) {
            return ['error' => 'Pick at least one product to track.'];
        }

        $out = [
            'tracking_type' => $type,
            'tracked_product_ids' => ($type === 'products' && $products) ? json_encode($products) : null,
        ];
        if ($hasCatCol) {
            $out['tracked_categories'] = ($type === 'category' && $categories) ? json_encode($categories) : null;
        }
        return $out;
    }

    /**
     * Results grouped by TEMPLATE: every campaign that used it, each with its
     * own funnel, plus one combined funnel counting each customer once.
     *
     * Answers two different questions at the same time — "how did each campaign
     * do?" and "how does this template perform?" — which is exactly why the
     * combined block deduplicates instead of summing the campaigns.
     */
    public function byTemplate(Request $request)
    {
        if ($deny = $this->denyIfNotPermitted()) return $deny;

        $template = trim((string) $request->get('template', ''));

        if ($template === '') {
            return response()->json([
                'success'   => true,
                'templates' => $this->statsService->templatesWithCampaigns(),
                'result'    => null,
            ]);
        }

        return response()->json([
            'success'   => true,
            'templates' => $this->statsService->templatesWithCampaigns(),
            'result'    => $this->statsService->forTemplate($template),
        ]);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    protected function quotaPayload(): array
    {
        $q = $this->sendService->quota();
        return [
            'cap'        => $q['cap'],
            'used'       => $q['used'],
            'remaining'  => $q['unlimited'] ? null : $q['remaining'],
            'unlimited'  => $q['unlimited'],
            'window'     => $q['window'],
            'default_session_limit' => $this->sendService->defaultSessionLimit(),
            'background_enabled'    => $this->sendService->backgroundEnabled(),
        ];
    }

    /**
     * How many of the matched customers have no phone number. They can never
     * receive a WhatsApp message, so telling the operator up front beats letting
     * them discover it as a pile of "No phone number" failures.
     */
    protected function countWithoutPhone(array $customerIds): int
    {
        if (empty($customerIds)) return 0;

        return (int) DB::table('t_crm_prod_customer')
            ->whereIn('id', $customerIds)
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('phone_normalized')->orWhere('phone_normalized', '');
                })->where(function ($w) {
                    $w->whereNull('phone')->orWhere('phone', '');
                });
            })
            ->count();
    }
}
