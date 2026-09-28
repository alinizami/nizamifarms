<?php

namespace App\Services\HQ;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Services\FIN\BankBalanceService;
use App\Services\QurbaniFinanceFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * HQ EXECUTIVE CLOSING SERVICE  (Phase 1 — read-only)
 * ===================================================
 * Powers the /hq executive dashboard: the "Monthly Closing" view and the
 * working-capital strip. This service is **strictly read-only** — it only
 * issues SELECT/aggregate queries and reuses existing read-only helpers
 * (BankBalanceService, AccountModel::getTotalEmployeeCashCalculatedBalance,
 * QurbaniFinanceFilter). It never writes, never mutates a balance, and does
 * not touch any operational endpoint. Safe to call from a cached AJAX route.
 *
 * Definitions (agreed with owner, Jul-2026) — kept deliberately explicit so
 * the numbers reconcile with existing surfaces:
 *
 *  • REVENUE is DELIVERED revenue, attributed to the day the order's status
 *    first/last became 'delivered' (t_crm_order_status_history.changed_at),
 *    NOT order_date. This is the "when did the money actually land" view the
 *    owner asked for. (The legacy /dashboard uses order_date; they differ on
 *    purpose.)
 *  • BUSINESS UNITS:
 *      - 'all'  = the regular ongoing business = NF + Khaas (Qurbani excluded).
 *      - 'nf'   = Nizami Farms portion of regular orders (line-item level).
 *      - 'kh'   = Khaas / frozen portion of regular orders (line-item level,
 *                 business_unit_id = Khaas). A single shared invoice is split
 *                 line by line; delivery charges & order discounts stay with NF
 *                 (owner rule) because NF = order total − Khaas lines.
 *      - 'qb'   = Qurbani. NOT a real business unit and NOT month-bound: it is
 *                 seasonal, so the Qurbani view is SEASON-TO-DATE (calendar
 *                 year). Qurbani has NO vendor purchases — its costs arrive as
 *                 expenses under the 'qurbani' request category. So Qurbani P&L
 *                 = revenue − qurbani expenses (no gross-profit step).
 *  • COGS (vendor purchases) and EXPENSES come from t_fin_ledger, counted at
 *    approval_status IN (approved, pending_l2) — balance applies at L1, so
 *    pending_l2 is effectively posted (same convention as BankBalanceService &
 *    the Finance monthly screen).
 *  • QURBANI is identified by QurbaniFinanceFilter (the single source of truth
 *    used by /qurbani/invoices and Monthly Reports), so Qurbani orders/expenses
 *    are cleanly separated and never double-counted against NF.
 */
class ExecutiveClosingService
{
    public const UNIT_ALL = 'all';
    public const UNIT_NF  = 'nf';
    public const UNIT_KH  = 'kh';
    public const UNIT_QB  = 'qb';

    /** Ledger rows whose balance has effectively posted (L1 onward). */
    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** Order statuses that count as delivered revenue. */
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    private ?int $khaasIdCache = null;

    public function __construct(private BankBalanceService $bankBalances)
    {
    }

    // =====================================================================
    //  PUBLIC ENTRY POINTS
    // =====================================================================

    /**
     * The full "Monthly Closing" KPI block for one unit + period, with the
     * previous period's figures for deltas. Cached 5 min.
     *
     * @param string $unit  one of UNIT_*
     * @param int    $year
     * @param int    $month 1-12 (ignored for Qurbani, which is season/year)
     */
    public function closing(string $unit, int $year, int $month, bool $fresh = false): array
    {
        $unit = $this->normalizeUnit($unit);
        $key  = "hq_closing_{$unit}_{$year}_{$month}";

        if ($fresh) {
            // Refresh button: drop this block's cache AND the underlying window
            // aggregates so the recompute really re-reads the database.
            Cache::forget($key);
            Cache::forget('hq_qurbani_order_ids');
            Cache::forget('hq_cust_delivery_spans');
            Cache::forget('hq_qb_season_' . $year);
            Cache::forget('hq_qb_season_' . ($year - 1));
            [$s, $e] = $this->period($unit, $year, $month);
            [$ps, $pe] = $this->previousPeriod($unit, $year, $month);
            // Cache key of the raw window now carries the cost-side (ledger) end;
            // current widens to the full month, previous stays exact.
            $le  = $this->ledgerEndFor($unit, $e) ?? $e;
            Cache::forget('hq_raw_' . $s->getTimestamp() . '_' . $e->getTimestamp() . '_' . $le->getTimestamp());
            Cache::forget('hq_raw_' . $ps->getTimestamp() . '_' . $pe->getTimestamp() . '_' . $pe->getTimestamp());
        }

        return Cache::remember($key, $this->ttlFor($unit, $year, $month), function () use ($unit, $year, $month) {
            [$start, $end, $label, $isOpen] = $this->period($unit, $year, $month);
            // Cost side spans the full calendar month (match Reports); revenue
            // stays to-date. Qurbani → null (seasonal, unchanged).
            $ledgerEnd = $this->ledgerEndFor($unit, $end);
            $cur  = $this->computeClosing($unit, $start, $end, $ledgerEnd);

            // Previous period (previous month, or previous year for Qurbani). Its
            // cost side stays exact (not displayed; only revenue drives deltas).
            [$pStart, $pEnd] = $this->previousPeriod($unit, $year, $month);
            $prev = $this->computeClosing($unit, $pStart, $pEnd);

            $workingDays = $this->workingDays($start, $end);

            return [
                'unit'          => $unit,
                'period_label'  => $label,
                'window'        => [
                    'start' => $start->toDateString(),
                    'end'   => $end->toDateString(),
                ],
                'is_open'       => $isOpen,
                'is_season'     => $unit === self::UNIT_QB,
                'working_days'  => $workingDays,
                'current'       => $cur,
                'previous'      => $prev,
                'derived'       => [
                    'aov'                   => $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 0) : 0,
                    'gp_margin'             => $cur['revenue'] > 0 ? round($cur['gross_profit'] / $cur['revenue'] * 100, 1) : 0,
                    'np_margin'             => $cur['revenue'] > 0 ? round($cur['net_profit'] / $cur['revenue'] * 100, 1) : 0,
                    'revenue_per_work_day'  => $workingDays > 0 ? round($cur['revenue'] / $workingDays, 0) : 0,
                    'orders_per_work_day'   => $workingDays > 0 ? round($cur['orders'] / $workingDays, 1) : 0,
                    'net_per_work_day'      => $workingDays > 0 ? round($cur['net_profit'] / $workingDays, 0) : 0,
                ],
            ];
        });
    }

    /**
     * Cache TTL policy: a CLOSED period's numbers barely change → cache 24h;
     * the open (current) period stays fresh at 5 min. Cuts both dashboard
     * latency and DB load on the shared host — the Refresh button's fresh=1
     * still bypasses everything.
     */
    private function ttlFor(string $unit, int $year, int $month): int
    {
        $now = Carbon::now();
        $isOpen = $unit === self::UNIT_QB
            ? ($year === $now->year)
            : ($year === $now->year && $month === $now->month);
        return $isOpen ? 300 : 86400;
    }

    /**
     * Six-month (or six-period) trend of revenue + net profit for a unit,
     * ending at the given year/month. Cached 5 min.
     */
    public function trend(string $unit, int $year, int $month, int $periods = 6, bool $fresh = false): array
    {
        $unit = $this->normalizeUnit($unit);
        $key  = "hq_trend_{$unit}_{$year}_{$month}_{$periods}";
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, $this->ttlFor($unit, $year, $month), function () use ($unit, $year, $month, $periods) {
            $out = [];
            $cursor = Carbon::create($year, $month, 1);
            for ($i = $periods - 1; $i >= 0; $i--) {
                $p = $cursor->copy()->subMonths($i);
                [$start, $end] = $this->period($unit, $p->year, $p->month);
                // Light path: trend only needs revenue + net profit, so skip the
                // heavy per-unit kg / distinct-customer / new-customer counts.
                $pt = $this->trendPoint($unit, $start, $end);
                $out[] = [
                    'label'      => $p->format('M y'),
                    'revenue'    => $pt['revenue'],
                    'net_profit' => $pt['net_profit'],
                ];
            }
            return $out;
        });
    }

    /**
     * Order-source split for the period: delivered regular orders broken into
     * App / Web / Manual (NF). Reuses deliveredBase() so the total reconciles
     * exactly with the "Overall" order count for the same month; Qurbani is
     * excluded (its own unit). Company-wide by design — origin is a property of
     * the whole order, not a line item, so this does NOT change with the
     * NF/Khaas toggle.
     *
     * Classification (owner ruling Jul-2026 — historical Shopify orders that
     * predate source tracking fold into Web):
     *   app    = order_source_channel in (ios_app, android_app)
     *   web    = NOT app AND (order_number LIKE 'SH-%' OR source_channel = 'web')
     *   manual = everything else (staff-created NF orders; no SH-/QUR number)
     */
    public function orderSourceSplit(int $year, int $month, bool $fresh = false): array
    {
        $key = "hq_order_split_{$year}_{$month}";
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, $this->ttlFor(self::UNIT_ALL, $year, $month), function () use ($year, $month) {
            [$start, $end, $label] = $this->period(self::UNIT_ALL, $year, $month);

            $appExpr = "o.order_source_channel IN ('ios_app','android_app')";
            $webExpr = "(o.order_source_channel IS NULL OR o.order_source_channel NOT IN ('ios_app','android_app')) "
                     . "AND (o.order_number LIKE 'SH-%' OR o.order_source_channel = 'web')";

            $row = $this->deliveredBase($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE)
                ->selectRaw(
                    "COUNT(DISTINCT o.id) total,
                     COUNT(DISTINCT CASE WHEN {$appExpr} THEN o.id END) app,
                     COUNT(DISTINCT CASE WHEN {$webExpr} THEN o.id END) web"
                )->first();

            $total  = (int) ($row->total ?? 0);
            $app    = (int) ($row->app ?? 0);
            $web    = (int) ($row->web ?? 0);
            $manual = max($total - $app - $web, 0); // remainder — always reconciles to total
            $pct = fn ($n) => $total > 0 ? round($n / $total * 100, 1) : 0.0;

            return [
                'period_label' => $label,
                'total'  => $total,
                'app'    => ['count' => $app,    'pct' => $pct($app)],
                'web'    => ['count' => $web,    'pct' => $pct($web)],
                'manual' => ['count' => $manual, 'pct' => $pct($manual)],
            ];
        });
    }

    /**
     * Company-wide working-capital snapshot (LIVE, as of now). Deliberately
     * NOT unit-split: cash, banks, rider cash and receivables are shared
     * across units, so this is a whole-company health strip. Cached 5 min.
     */
    /**
     * Working capital, SCOPED (owner rule, round 3): the Qurbani money lives in
     * its own accounts and must never appear in the NF / Khaas / Overall view —
     * and the Qurbani view must show ONLY Qurbani money.
     *   scope 'regular' (unit all/nf/kh): all cash+online EXCEPT QURBANI_*,
     *     receivables = approvals + shop + rider, payables, assets, health.
     *   scope 'qurbani' (unit qb): QURBANI_CASH + QURBANI_ONLINE + this
     *     season's order balances; no vendors, no assets, no health tile.
     */
    public function workingCapital(string $unit = self::UNIT_NF, bool $fresh = false): array
    {
        $u = $this->normalizeUnit($unit);
        if ($u === self::UNIT_QB) {
            if ($fresh) Cache::forget('hq_working_capital_qurbani');
            return Cache::remember('hq_working_capital_qurbani', 300, fn () => $this->qurbaniWorkingCapital());
        }
        // Regular scope: the shared cash/online/receivables/payables are the same
        // across NF/Khaas/all; only the FIXED ASSETS card follows the unit
        // selector, so we cache one payload per regular unit.
        $key = "hq_working_capital_regular_{$u}";
        if ($fresh) Cache::forget($key);
        return Cache::remember($key, 300, fn () => $this->regularWorkingCapital($u));
    }

    /** Qurbani-only position: its own cash + online accounts + season balances. */
    private function qurbaniWorkingCapital(): array
    {
        $cashRows = AccountModel::where('account_category', AccountModel::CATEGORY_CASH)
            ->where('is_active', 1)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, true))
            ->get(['account_name', 'current_balance']);
        $cash = (float) $cashRows->sum('current_balance');

        $onlineAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, true))
            ->get(['account_name', 'current_balance'])
            ->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])
            ->all();
        $online = array_sum(array_column($onlineAccounts, 'balance'));

        $season = $this->qurbaniSeasonReceivable();
        $receivables = $season['amount'];
        $net = $cash + $online + $receivables; // no vendor payables — Qurbani has no vendors

        return [
            'scope'       => 'qurbani',
            'as_of'       => Carbon::now()->toDateTimeString(),
            'cash'        => round($cash, 0),
            'cash_total'  => round($cash, 0),
            'cash_accounts' => $cashRows->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])->skip(1)->values()->all(),
            'cash_label'  => $cashRows->first()->account_name ?? 'Qurbani Cash',
            'online'      => round($online, 0),
            'online_accounts' => $onlineAccounts,
            'receivables' => [
                'total'   => round($receivables, 0),
                'season'  => round($season['amount'], 0),
                'season_count' => $season['count'],
                'regular' => 0, 'regular_count' => 0, 'shop' => 0, 'shop_count' => 0,
                'rider' => 0, 'overdue' => 0,
            ],
            'payables'    => 0,
            'assets'      => null,
            'net'         => round($net, 0),
            'health'      => [],
        ];
    }

    /** WHERE fragment: qurbani-owned accounts (code/name carry QURBANI). */
    private function qurbaniAccountWhere($q, bool $isQurbani): void
    {
        if ($isQurbani) {
            $q->where(function ($w) {
                $w->where('account_code', 'like', 'QURBANI%')
                  ->orWhereRaw("LOWER(account_name) LIKE '%qurbani%'");
            });
        } else {
            $q->where(function ($w) {
                $w->where(function ($x) {
                    $x->where('account_code', 'not like', 'QURBANI%')
                      ->whereRaw("LOWER(account_name) NOT LIKE '%qurbani%'");
                })->orWhereNull('account_code');
            });
        }
    }

    /** Current season's delivered qurbani orders not fully paid (QUR{yy}-). */
    private function qurbaniSeasonReceivable(): array
    {
        $prefix = 'QUR' . Carbon::now()->format('y') . '%';
        $row = DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->whereColumn('o.total_paid', '<', 'o.total_price')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(o.total_price - COALESCE(o.total_paid,0)),0) amt')
            ->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    /** Regular (NF + Khaas) position — every QURBANI_* account excluded. */
    private function regularWorkingCapital(string $unit = self::UNIT_ALL): array
    {
        // --- Cash in hand. Headline = NF_CASH (the actual till, owner rule);
        //     other REGULAR cash accounts (Khaas till, expense fund) are shown
        //     as labelled rows. QURBANI_CASH is excluded — it belongs to the
        //     Qurbani view only (owner rule, round 3). ---
        $cashRows = AccountModel::where('account_category', AccountModel::CATEGORY_CASH)
            ->where('is_active', 1)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, false))
            ->orderByRaw("CASE WHEN account_code = 'NF_CASH' THEN 0 ELSE 1 END")
            ->get(['account_code', 'account_name', 'current_balance']);
        $nfCash = 0.0; $cashTotal = 0.0; $cashList = [];
        foreach ($cashRows as $a) {
            $bal = (float) $a->current_balance;
            $cashTotal += $bal;
            if ($a->account_code === 'NF_CASH') { $nfCash = $bal; continue; }
            if (abs($bal) >= 1) {
                $cashList[] = ['name' => $a->account_name, 'balance' => round($bal, 0)];
            }
        }

        // --- Online. Headline = the REGULAR online ledger (the ONLINE account;
        //     QURBANI_ONLINE excluded — Qurbani view only). The per-bank rows
        //     are an unreconciled SEGREGATION and live in the drill. ---
        $onlineAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, false))
            ->orderByRaw("CASE WHEN account_code = 'ONLINE' THEN 0 ELSE 1 END")
            ->get(['account_name', 'current_balance'])
            ->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])
            ->all();
        $online = array_sum(array_column($onlineAccounts, 'balance'));

        // The FULL pool (incl. Qurbani Online) — only for the health check,
        // because the per-bank segregation spans both online accounts.
        $allOnlinePool = (float) AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->sum('current_balance');
        $banksById = $this->bankBalances->balancesByBank();
        $bankMeta  = DB::table('t_fin_online_receiving_accounts')
            ->get(['id', 'name', 'short_code', 'color_hex'])->keyBy('id');
        $banks = [];
        $trackedSum = 0.0;
        foreach ($banksById as $id => $b) {
            $trackedSum += $b['balance'];
            if (abs($b['balance']) < 1) continue; // hide zero rows; drill shows all
            $meta = $bankMeta[$id] ?? null;
            $banks[] = [
                'name'    => $meta->name ?? ('Bank #' . $id),
                'short'   => $meta->short_code ?? '',
                'color'   => $meta->color_hex ?? '#64748b',
                'balance' => round($b['balance'], 0),
            ];
        }
        usort($banks, fn ($a, $b) => $b['balance'] <=> $a['balance']);
        $unassigned = round($this->bankBalances->unassignedNet(), 0);

        // --- Payables: what we owe vendors (positive = we owe). ---
        $payables = (float) AccountModel::where('account_category', AccountModel::CATEGORY_VENDOR_PAYABLE)
            ->where('is_active', 1)
            ->sum('current_balance');

        // 💵 Tips held for staff are owed money too (Sep-2026, owner ruling A3).
        // The cash is sitting in our tills and banks and is counted in `cash` /
        // `online` above, so without subtracting it here the working capital
        // would claim money we are only holding.
        $tipsHeld = (float) AccountModel::where('account_code', AccountModel::CODE_TIPS_FUND)
            ->where('is_active', 1)
            ->sum('current_balance');
        $payables += $tipsHeld;

        // --- Receivables: mirrors ONLINE APPROVALS (owner rule — legacy
        //     orders are considered closed). regular = pending invoice
        //     ledger rows (All Pending); shop = the Shop tab's outstanding;
        //     + rider cash to settle. Qurbani is NOT here. ---
        $regular = $this->regularPendingReceivable();
        $shop    = $this->shopOutstandingReceivable();
        $rider   = round(AccountModel::getTotalEmployeeCashCalculatedBalance(), 0);
        $receivables = $regular['amount'] + $shop['amount'] + $rider;

        // --- Fixed assets (info card — NOT part of net working capital).
        //     Follows the unit selector: NF → NF assets, Khaas → frozen, all →
        //     both (owner rule, round 4). ---
        $assets = ['count' => 0, 'value' => 0, 'units' => []];
        try {
            $khaasId = $this->khaasBusinessUnitId();
            $aq = DB::table('t_fin_assets as a')
                ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
                ->where('a.status', 'active');
            if ($unit === self::UNIT_NF) {
                $aq->where(function ($w) { $w->where('a.business_unit_id', 1)->orWhereNull('a.business_unit_id'); });
            } elseif ($unit === self::UNIT_KH) {
                $aq->where('a.business_unit_id', $khaasId ?? -1);
            }
            $rows = $aq->groupBy('a.business_unit_id')
                ->selectRaw('COALESCE(MAX(bu.name),"Nizami Farms") unit, COUNT(*) n, COALESCE(SUM(a.current_book_value),0) v')
                ->get();
            foreach ($rows as $r) {
                $assets['count'] += (int) $r->n;
                $assets['value'] += (float) $r->v;
                $assets['units'][] = ['unit' => $r->unit, 'count' => (int) $r->n, 'value' => round((float) $r->v, 0)];
            }
            $assets['value'] = round($assets['value'], 0);
        } catch (\Throwable $e) { /* assets module optional */ }

        $net = $cashTotal + $online + $receivables - $payables;

        return [
            'scope'       => 'regular',
            'as_of'       => Carbon::now()->toDateTimeString(),
            'cash'        => round($nfCash, 0),
            'cash_total'  => round($cashTotal, 0),
            'cash_accounts' => $cashList,
            'cash_label'  => 'NF Cash (Main Till)',
            'online'      => round($online, 0),
            'online_accounts' => $onlineAccounts,
            'online_tracked' => round($trackedSum, 0),
            'online_unassigned' => $unassigned,
            'banks'       => $banks,
            'receivables' => [
                'total'    => round($receivables, 0),
                'regular'  => round($regular['amount'], 0),
                'regular_count' => $regular['count'],
                'shop'     => round($shop['amount'], 0),
                'shop_count' => $shop['count'],
                'rider'    => $rider,
                'overdue'  => round($regular['overdue'], 0),
            ],
            'payables'    => round($payables, 0),
            // Split out so the card can say WHY payables moved: vendors owed
            // versus tips we are holding for staff.
            'payables_vendors' => round($payables - $tipsHeld, 0),
            'payables_tips'    => round($tipsHeld, 0),
            'assets'      => $assets,
            'net'         => round($net, 0),
            'health'      => $this->healthChecks($trackedSum + $unassigned, $allOnlinePool),
        ];
    }

    /** Fixed assets → drill: the active assets themselves (unit-scoped). */
    public function assetsList(string $unit = self::UNIT_ALL): array
    {
        $unit = $this->normalizeUnit($unit);
        try {
            $q = DB::table('t_fin_assets as a')
                ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
                ->where('a.status', 'active');
            if ($unit === self::UNIT_NF) {
                $q->where(function ($w) { $w->where('a.business_unit_id', 1)->orWhereNull('a.business_unit_id'); });
            } elseif ($unit === self::UNIT_KH) {
                $khaasId = $this->khaasBusinessUnitId();
                $q->where('a.business_unit_id', $khaasId ?? -1);
            }
            return $q
                ->orderByDesc('a.current_book_value')
                ->limit(300)
                ->get([
                    'a.asset_name', 'a.purchase_date', 'a.current_book_value',
                    DB::raw('COALESCE(bu.name, "Nizami Farms") as unit'),
                    'a.location',
                ])
                ->map(fn ($r) => [
                    'asset'    => $r->asset_name,
                    'unit'     => $r->unit,
                    'location' => $r->location ?: '—',
                    'bought'   => $r->purchase_date ? Carbon::parse($r->purchase_date)->format('M y') : '—',
                    'value'    => round((float) $r->current_book_value),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Health → drill: the delivered orders (last 30 days) that have no invoice
     * ledger entry. These are the ones the health tile flags — money delivered
     * that never got booked to the ledger. Read-only list so the owner (or
     * finance) can chase each one.
     */
    public function missingInvoices(): array
    {
        try {
            return $this->missingInvoiceBase()
                ->orderByDesc('d.delivered_at')->limit(300)
                ->get([
                    'o.order_number', 'o.total_price', 'o.total_paid', 'o.payment_method',
                    'd.delivered_at',
                    DB::raw('TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,""))) as name'),
                ])
                ->map(fn ($r) => [
                    'order_number' => $r->order_number,
                    'customer'     => trim($r->name) !== '' ? trim($r->name) : 'Walk-in',
                    'type'         => 'regular',
                    'delivered'    => $r->delivered_at ? Carbon::parse($r->delivered_at)->format('M d') : '—',
                    'amount'       => round((float) $r->total_price),
                    'paid'         => $this->paymentLabel($r->payment_method, (float) $r->total_paid, (float) $r->total_price),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Shared base for the missing-invoice count + list: delivered REGULAR
     * (non-shop, non-Shopify) orders in the last 30 days that have no invoice
     * ledger entry. Shop customers are excluded — they settle incrementally
     * and by design never post a full invoice.
     */
    private function missingInvoiceBase()
    {
        return DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub(Carbon::now()->subDays(30), Carbon::now()), 'd', 'd.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
            })
            ->where(function ($w) {
                $w->whereNull('c.customer_type')->orWhere('c.customer_type', '!=', 'shop');
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('t_fin_ledger as l')
                    ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                    ->whereColumn('l.order_id', 'o.id');
            });
    }

    /**
     * Regular-customer receivable = the Online Approvals "All Pending" number:
     * pending / pending_l1 / pending_l2 online ledger rows with no request,
     * excluding shop-customer orders. EXACT mirror of ApprovalController's
     * L1+L2 tabs so this card always reconciles with that screen.
     */
    private function regularPendingReceivable(): array
    {
        $base = DB::table('t_fin_ledger as l')
            ->whereIn('l.approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_PENDING_L2,
            ])
            ->whereNull('l.request_id')
            ->where('l.mode', LedgerModel::MODE_ONLINE)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_crm_prod_order as so')
                    ->join('t_crm_prod_customer as sc', 'sc.id', '=', 'so.customer_id')
                    ->whereColumn('so.id', 'l.order_id')
                    ->where('sc.customer_type', 'shop');
            });

        $row = (clone $base)->selectRaw('COUNT(*) n, COALESCE(SUM(l.amount),0) amt')->first();
        $overdue = (float) (clone $base)
            ->where('l.transaction_date', '<', Carbon::now()->subDays(30))
            ->sum('l.amount');

        return ['amount' => (float) $row->amt, 'count' => (int) $row->n, 'overdue' => $overdue];
    }

    /**
     * Shop receivable = the Online Approvals Shop tab total: delivered online
     * orders of shop customers with no booked (approved / pending_l2) invoice;
     * outstanding = total − paid. EXACT mirror of getOnlineShopItems.
     */
    private function shopOutstandingReceivable(): array
    {
        $row = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('c.customer_type', 'shop')
            ->whereIn('o.payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->where('o.order_status', 'delivered')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_fin_ledger as l')
                    ->whereColumn('l.order_id', 'o.id')
                    ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                    ->whereIn('l.approval_status', [
                        LedgerModel::STATUS_APPROVED,
                        LedgerModel::STATUS_PENDING_L2,
                    ]);
            })
            ->selectRaw('COUNT(*) n, COALESCE(SUM(GREATEST(o.total_price - COALESCE(o.total_paid,0), 0)),0) amt')
            ->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    // =====================================================================
    //  GROWTH  (Phase 2) — the "customer engine". All read-only, cached.
    //  Two layers: SALES QUALITY (AOV / new / repeat — unit-aware, from the
    //  closing block) and the CUSTOMER ENGINE (active 30/60/90, recency ladder,
    //  cohort retention — computed on the WHOLE regular customer base, because a
    //  customer is one relationship whether they buy fresh or frozen). Qurbani
    //  gets a simplified seasonal variant (rolling windows don't fit a season).
    // =====================================================================

    public function growth(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_growth_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->growthCompute($unit, $year, $month));
    }

    private function growthCompute(string $unit, int $year, int $month): array
    {
        $closing = $this->closing($unit, $year, $month); // cached; unit-aware
        $cur = $closing['current']; $prev = $closing['previous'];
        $aov     = $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 0) : 0;
        $aovPrev = $prev['orders'] > 0 ? round($prev['revenue'] / $prev['orders'], 0) : 0;
        $repeat     = $cur['customers'] > 0 ? ($cur['customers'] - $cur['new_customers']) / $cur['customers'] : 0;
        $repeatPrev = $prev['customers'] > 0 ? ($prev['customers'] - $prev['new_customers']) / $prev['customers'] : 0;

        $isQb = $unit === self::UNIT_QB;
        return [
            'unit'         => $unit,
            'is_season'    => $isQb,
            'is_open'      => $closing['is_open'],
            'period_label' => $closing['period_label'],
            'sales' => [
                'aov'           => $aov,
                'aov_prev'      => $aovPrev,
                'new_customers' => $cur['new_customers'],
                'new_prev'      => $prev['new_customers'],
                'repeat'        => round($repeat * 100, 1),
                'repeat_prev'   => round($repeatPrev * 100, 1),
                'customers'     => $cur['customers'],
                'returning'     => max($cur['customers'] - $cur['new_customers'], 0),
            ],
            'engine' => $isQb ? $this->qurbaniGrowthEngine($year) : $this->regularGrowthEngine(),
            'cohort' => $isQb ? null : $this->cohortRetention(),
        ];
    }

    /**
     * Every regular customer's FIRST and LAST delivery. "Regular" = the growth base:
     * non-Shopify, non-Qurbani, currently delivered/completed.
     *
     * FIRST drives the DELIVERY clock (owner ruling, Jul-2026): a customer is "new"
     * in the month they first RECEIVED an order, not the month they placed it — the
     * same clock as revenue and every other HQ number. The old `first_order_date`
     * (placement) clock silently lost anyone who ordered late in one month and was
     * delivered in the next: their placement month had no delivery, and their
     * delivery month's placement fell in the prior month, so they were never counted
     * as new in ANY month. It also treated a Qurbani-only buyer's first regular
     * purchase as "returning"; since Qurbani is excluded from this base, their first
     * regular delivery now correctly makes them new here (owner ruling).
     *
     * ONE pass over delivery history → every regular customer's FIRST and LAST
     * delivery. Deliberately shared: the recency ladder / active counts need the
     * LAST, the new-customer count and the cohort need the FIRST. That scan is the
     * most expensive query on this page (the history table has no index on
     * status_code, so it reads ~45k rows), so it runs ONCE per hour here instead of
     * once per consumer.
     *
     * @return array<int,array{first:string,last:string}>
     */
    private function customerDeliverySpans(): array
    {
        return Cache::remember('hq_cust_delivery_spans', 3600, function () {
            $ids = $this->qurbaniOrderIds();
            $rows = DB::table('t_crm_prod_order as o')
                ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->where(function ($w) {
                    $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
                })
                ->whereNotNull('o.customer_id')
                ->when(!empty($ids), fn ($q) => $q->whereNotIn('o.id', $ids))
                ->groupBy('o.customer_id')
                ->selectRaw('o.customer_id id, MIN(d.delivered_at) first_del, MAX(d.delivered_at) last_del')
                ->get();
            $out = [];
            foreach ($rows as $r) {
                if (!$r->first_del) continue;
                $out[(int) $r->id] = ['first' => $r->first_del, 'last' => $r->last_del];
            }
            return $out;
        });
    }

    /** Ids of customers whose FIRST regular delivery falls inside the window. */
    private function newCustomerIds(Carbon $start, Carbon $end): array
    {
        $s = $start->toDateTimeString();
        $e = $end->toDateTimeString();
        $out = [];
        foreach ($this->customerDeliverySpans() as $cid => $sp) {
            if ($sp['first'] >= $s && $sp['first'] <= $e) $out[] = $cid;
        }
        return $out;
    }

    /**
     * Active-customer counts + the recency ladder for the whole regular customer
     * base, computed from one grouped "last delivered order per customer" query.
     * Cached for the day (the windows are relative to today).
     */
    private function regularGrowthEngine(): array
    {
        return Cache::remember('hq_growth_engine_' . Carbon::now()->format('Y-m-d'), 3600, function () {
            // Reuses the shared delivery-span scan (see customerDeliverySpans) —
            // the ladder only needs each customer's LAST delivery.
            $rows = $this->customerDeliverySpans();

            // Calendar-day recency (date minus date), matching the drill's SQL
            // DATEDIFF exactly — fractional-day counting put ~19 customers in a
            // different band on the tile than in its own drill list.
            $today = Carbon::now()->startOfDay();
            $a30 = $a60 = $a90 = 0;
            // bands: 0-30, 31-60, 61-90, 91-180, 180+
            $bands = [0, 0, 0, 0, 0];
            foreach ($rows as $sp) {
                if (!$sp['last']) continue;
                $days = (int) Carbon::parse(substr($sp['last'], 0, 10))->diffInDays($today);
                if ($days <= 30) { $a30++; $bands[0]++; }
                elseif ($days <= 60) { $bands[1]++; }
                elseif ($days <= 90) { $bands[2]++; }
                elseif ($days <= 180) { $bands[3]++; }
                else { $bands[4]++; }
                if ($days <= 60) $a60++;
                if ($days <= 90) $a90++;
            }
            $total = count($rows);
            $bandDefs = [
                ['key' => '0-30',   'label' => '0–30 days',    'count' => $bands[0], 'winback' => false],
                ['key' => '31-60',  'label' => '31–60 days',   'count' => $bands[1], 'winback' => false],
                ['key' => '61-90',  'label' => '61–90 days',   'count' => $bands[2], 'winback' => true],
                ['key' => '91-180', 'label' => '91–180 days',  'count' => $bands[3], 'winback' => false],
                ['key' => '180+',   'label' => '180+ · lapsed','count' => $bands[4], 'winback' => false],
            ];
            return [
                'active_30' => $a30, 'active_60' => $a60, 'active_90' => $a90,
                'total_customers' => $total,
                'bands' => $bandDefs,
            ];
        });
    }

    /** Qurbani seasonal growth: season buyers + returning-from-last-season. */
    private function qurbaniGrowthEngine(int $year): array
    {
        // Season identity = QUR{yy} numbers OR (2025) product-backfilled orders.
        $ids     = $this->qurbaniSeasonOrderIds($year) ?: [0];
        $prevIds = $this->qurbaniSeasonOrderIds($year - 1) ?: [0];

        $buyers = (int) DB::table('t_crm_prod_order as o')
            ->whereIn('o.id', $ids)
            ->where('o.order_status', '<>', 'cancelled')
            ->distinct()->count('o.customer_id');
        // returning = this season's buyers who also bought last season
        $returning = (int) DB::table('t_crm_prod_order as o')
            ->whereIn('o.id', $ids)
            ->where('o.order_status', '<>', 'cancelled')
            ->whereIn('o.customer_id', function ($q) use ($prevIds) {
                $q->select('customer_id')->from('t_crm_prod_order')
                    ->whereIn('id', $prevIds)
                    ->where('order_status', '<>', 'cancelled');
            })
            ->distinct()->count('o.customer_id');

        return [
            'season'        => true,
            'buyers'        => $buyers,
            'returning'     => $returning,
            'new_to_season' => max($buyers - $returning, 0),
            'retention_pct' => $buyers > 0 ? round($returning / $buyers * 100, 1) : 0,
            'mix'           => $this->qurbaniCustomerMix($year),
        ];
    }

    /**
     * "Did the Qurbani season build the regular business?" (owner ask, Jul-2026).
     * Takes everyone who bought Qurbani this season and answers two questions:
     *
     *   1. NEW to Nizami Farms, or EXISTING? New = that Qurbani order was their
     *      first-ever order with us (nothing placed before it). Existing = they had
     *      already ordered something (regular, or a previous Qurbani season).
     *   2. Do they ALSO buy regular meat = do they have any delivered non-Qurbani
     *      order, ever?
     *
     * For the NEW group, "also buys regular" literally means CONVERTED: the Qurbani
     * order was their first contact with us, so any regular order came afterwards.
     * new_only is therefore the win-back pool — people Qurbani brought in who have
     * never bought meat from us since.
     *
     * Season identity = qurbaniSeasonOrderIds (QUR{yy} numbers, or product-
     * backfilled orders for 2025). "Regular" excludes Qurbani via the canonical
     * filter, so an order that is Qurbani-flagged but not QUR-numbered still
     * won't count as regular.
     * Three set-wise queries (no correlated subqueries) keyed on ~600 buyer ids.
     */
    private function qurbaniCustomerMix(int $year): array
    {
        $empty = [
            'buyers'   => 0,
            'new'      => ['total' => 0, 'regular' => 0, 'only' => 0, 'pct' => 0],
            'existing' => ['total' => 0, 'regular' => 0, 'only' => 0, 'pct' => 0],
        ];
        try {
            $seasonIds = $this->qurbaniSeasonOrderIds($year);
            if (empty($seasonIds)) return $empty;

            // 1. Season buyers + the date of their FIRST Qurbani order this season.
            $buyers = DB::table('t_crm_prod_order')
                ->whereIn('id', $seasonIds)
                ->where('order_status', '<>', 'cancelled')
                ->whereNotNull('customer_id')
                ->groupBy('customer_id')
                ->selectRaw('customer_id cid, MIN(order_date) first_qur')
                ->get();
            if ($buyers->isEmpty()) return $empty;
            $cids = $buyers->pluck('cid')->map(fn ($v) => (int) $v)->all();

            // 2. Their earliest order of ANY kind (Shopify-source excluded, matching
            //    how first_order_date is defined). Earlier than their first Qurbani
            //    order → they already knew us.
            $earliest = DB::table('t_crm_prod_order')
                ->whereIn('customer_id', $cids)
                ->where(function ($w) {
                    $w->whereNull('external_source')->orWhere('external_source', '<>', 'shopify');
                })
                ->groupBy('customer_id')
                ->selectRaw('customer_id cid, MIN(order_date) first_any')
                ->pluck('first_any', 'cid');

            // 3. Which of them have ever RECEIVED a regular (non-Qurbani) order.
            $qids = $this->qurbaniOrderIds();
            $regular = DB::table('t_crm_prod_order')
                ->whereIn('customer_id', $cids)
                ->whereIn('order_status', self::DELIVERED_STATUSES)
                ->where(function ($w) {
                    $w->whereNull('external_source')->orWhere('external_source', '<>', 'shopify');
                })
                ->when(!empty($qids), fn ($q) => $q->whereNotIn('id', $qids))
                ->distinct()->pluck('customer_id')
                ->map(fn ($v) => (int) $v)->flip();

            $out = $empty;
            foreach ($buyers as $b) {
                $cid    = (int) $b->cid;
                $first  = $earliest[$b->cid] ?? null;
                // No earlier order than their first Qurbani one → new to us.
                $isNew  = $first === null || $first >= $b->first_qur;
                $hasReg = $regular->has($cid);
                $g = $isNew ? 'new' : 'existing';
                $out[$g]['total']++;
                $hasReg ? $out[$g]['regular']++ : $out[$g]['only']++;
            }
            $out['buyers'] = $buyers->count();
            foreach (['new', 'existing'] as $g) {
                $out[$g]['pct'] = $out[$g]['total'] > 0
                    ? round($out[$g]['regular'] / $out[$g]['total'] * 100, 1) : 0;
            }
            return $out;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Cohort retention — for the last 6 acquisition months, the % of each
     * cohort that placed another delivered order in month +1/+2/+3. Cells for
     * months that haven't finished yet are null (shown as "—"). Regular base.
     */
    private function cohortRetention(): array
    {
        return Cache::remember('hq_cohort_' . Carbon::now()->format('Y-m'), 3600, function () {
            $ids = $this->qurbaniOrderIds();
            $start = Carbon::now()->subMonths(5)->startOfMonth();
            // Cohort membership uses the SAME delivery clock as "new customers"
            // (owner ruling) — a cohort IS that month's new customers, so the row
            // size must equal the New-customers card for that month.
            $startStr = $start->toDateTimeString();
            $custs = [];
            foreach ($this->customerDeliverySpans() as $cid => $sp) {
                if ($sp['first'] >= $startStr) $custs[$cid] = $sp['first'];
            }
            if (empty($custs)) return ['months' => []];

            $custIds = array_keys($custs);
            $orderMonths = DB::table('t_crm_prod_order as o')
                ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
                ->whereIn('o.customer_id', $custIds)
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->when(!empty($ids), fn ($q) => $q->whereNotIn('o.id', $ids))
                ->selectRaw("o.customer_id cid, DATE_FORMAT(d.delivered_at, '%Y-%m') ym")
                ->distinct()->get();
            $byCust = [];
            foreach ($orderMonths as $r) { $byCust[$r->cid][$r->ym] = true; }

            $nowMonth = Carbon::now()->format('Y-m'); // current month is incomplete
            $coh = [];
            foreach ($custs as $cid => $firstDel) {
                $fm = Carbon::parse($firstDel)->startOfMonth();
                $key = $fm->format('Y-m');
                $coh[$key]['size'] = ($coh[$key]['size'] ?? 0) + 1;
                for ($k = 1; $k <= 3; $k++) {
                    $mk = $fm->copy()->addMonths($k)->format('Y-m');
                    if (!empty($byCust[$cid][$mk])) {
                        $coh[$key]['m' . $k] = ($coh[$key]['m' . $k] ?? 0) + 1;
                    }
                }
            }
            ksort($coh);
            $out = [];
            foreach ($coh as $key => $v) {
                $fm = Carbon::createFromFormat('!Y-m', $key)->startOfMonth();
                $size = (int) $v['size'];
                $cells = [];
                for ($k = 1; $k <= 3; $k++) {
                    $mk = $fm->copy()->addMonths($k)->format('Y-m');
                    // A follow-on month that is the current (incomplete) month or
                    // later hasn't fully happened → show as null.
                    $cells[] = ($mk >= $nowMonth || $size === 0)
                        ? null
                        : round(($v['m' . $k] ?? 0) / $size * 100);
                }
                $out[] = [
                    'label' => $fm->format('M y'),
                    'size'  => $size,
                    'cells' => $cells,
                ];
            }
            // Only the last 6 acquisition months, and drop the current (still
            // filling) month's own cohort row if it's tiny/incomplete-only.
            return ['months' => array_slice($out, -6)];
        });
    }

    /** Recency band → drill: the customers in one band (win-back campaign list). */
    public function recencyList(string $band): array
    {
        $map = [
            '0-30'   => [0, 30],   '31-60' => [31, 60], '61-90' => [61, 90],
            '91-180' => [91, 180], '180+'  => [181, 100000],
        ];
        [$min, $max] = $map[$band] ?? [61, 90];
        $ids = $this->qurbaniOrderIds();

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
            })
            ->whereNotNull('o.customer_id')
            ->when(!empty($ids), fn ($qq) => $qq->whereNotIn('o.id', $ids))
            ->groupBy('o.customer_id')
            ->havingRaw('DATEDIFF(NOW(), MAX(d.delivered_at)) BETWEEN ? AND ?', [$min, $max]);

        return $q->select(
            'o.customer_id',
            DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
            DB::raw('MAX(COALESCE(c.phone_original, c.phone)) as phone'),
            DB::raw('MAX(c.customer_type) as type'),
            DB::raw('MAX(d.delivered_at) as last_del'),
            DB::raw('COUNT(DISTINCT o.id) as orders'),
            DB::raw('SUM(o.total_price) as spent')
        )->orderByDesc('spent')->limit(400)->get()
            ->map(fn ($r) => [
                'customer'  => trim($r->name) !== '' ? trim($r->name) : 'Walk-in',
                'phone'     => $r->phone ?: '—',
                'type'      => $r->type ?: 'regular',
                'last'      => $r->last_del ? Carbon::parse($r->last_del)->format('M d, y') : '—',
                // Calendar days (date minus date) — same arithmetic as the SQL
                // DATEDIFF that filtered this list, so the shown age always
                // matches the band the customer was selected into.
                'days'      => $r->last_del ? (int) Carbon::parse(substr($r->last_del, 0, 10))->diffInDays(Carbon::now()->startOfDay()) : 0,
                'orders'    => (int) $r->orders,
                'spent'     => round((float) $r->spent),
            ])->all();
    }

    // =====================================================================
    //  DRILL-DOWNS  (Level 1 summary → Level 2 detail). All read-only.
    //  Each reuses the SAME definitions as the KPI cards, so totals reconcile.
    // =====================================================================

    /** Revenue → Level 1: per delivered-day summary for the unit + month. */
    public function revenueDaily(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_revdaily_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->revenueDailyCompute($unit, $year, $month));
    }

    private function revenueDailyCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();
        $dayExpr = DB::raw('DATE(d.delivered_at) as day');

        // Overall per-day (order-level) + Khaas per-day (line-level); derive NF.
        $kh = (int) ($khaasId ?: -1);
        $pieceCase = self::pieceCaseSql($kh);
        // Sep-2026 — same profit-revenue lens as the P&L cards, so the daily
        // chart and the month total tell the same story.
        // ⚠ Qurbani keeps its tips: they never enter the Tips Fund, so removing
        // them here would lose the money instead of relocating it.
        $dayRevExpr = \App\Services\FIN\ProfitRevenueSql::revenue(
            'o',
            \App\Services\FIN\ProfitRevenueSql::DEL_ALIAS . '.first_delivered_at',
            \App\Services\FIN\ProfitRevenueSql::BAL_ALIAS,
            $unit !== self::UNIT_QB
        );
        $overallQ = $this->deliveredBase($start, $end, $qmode);
        \App\Services\FIN\ProfitRevenueSql::join($overallQ, 'o');
        \App\Services\FIN\ProfitRevenueSql::joinDelivered($overallQ, 'o');
        $overall = $overallQ
            ->select($dayExpr, DB::raw('COUNT(DISTINCT o.id) as orders'), DB::raw("SUM($dayRevExpr) as revenue"))
            ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');
        $overallQty = $this->deliveredLines($start, $end, $qmode)
            ->select(DB::raw('DATE(d.delivered_at) as day'),
                     DB::raw("SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END) as kg"),
                     DB::raw("SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END) as pcs"))
            ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');

        $khRev = collect();
        if ($khaasId && $unit !== self::UNIT_QB) {
            $khRev = $this->deliveredLines($start, $end, $qmode)->where('p.business_unit_id', $khaasId)
                ->select(DB::raw('DATE(d.delivered_at) as day'), DB::raw('SUM(li.line_total) as rev'),
                         DB::raw("SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END) as kg"),
                         DB::raw("SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END) as pcs"),
                         DB::raw('COUNT(DISTINCT o.id) as orders'))
                ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');
        }

        $rows = [];
        foreach ($overall as $day => $o) {
            $oRev = (float) $o->revenue; $oOrd = (int) $o->orders;
            $q = $overallQty[$day] ?? null;
            $oKg = (float) ($q->kg ?? 0); $oPcs = (float) ($q->pcs ?? 0);
            $k = $khRev[$day] ?? null;
            $kRev = (float) ($k->rev ?? 0); $kKg = (float) ($k->kg ?? 0);
            $kPcs = (float) ($k->pcs ?? 0); $kOrd = (int) ($k->orders ?? 0);
            if ($unit === self::UNIT_KH) {
                if ($kOrd === 0) continue;
                $rows[] = ['day' => $day, 'orders' => $kOrd, 'kg' => round($kKg), 'pcs' => round($kPcs), 'revenue' => round($kRev)];
            } elseif ($unit === self::UNIT_NF) {
                $rows[] = ['day' => $day, 'orders' => $oOrd, 'kg' => round(max($oKg - $kKg, 0)), 'pcs' => round(max($oPcs - $kPcs, 0)), 'revenue' => round($oRev - $kRev)];
            } else {
                $rows[] = ['day' => $day, 'orders' => $oOrd, 'kg' => round($oKg), 'pcs' => round($oPcs), 'revenue' => round($oRev)];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['day'], $b['day']));
        return $rows;
    }

    /** Revenue → Level 2: the orders delivered on one day for the unit. */
    public function revenueOrdersForDay(string $unit, string $date): array
    {
        $unit = $this->normalizeUnit($unit);
        $start = Carbon::parse($date)->startOfDay();
        $end   = Carbon::parse($date)->endOfDay();
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();

        $q = $this->deliveredBase($start, $end, $qmode)
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id');

        // For Khaas, limit to orders that carry a Khaas line.
        if ($unit === self::UNIT_KH && $khaasId) {
            $q->whereExists(function ($sub) use ($khaasId) {
                $sub->select(DB::raw(1))->from('t_crm_prod_order_line_item as li2')
                    ->join('t_crm_prod_product as p2', 'p2.id', '=', 'li2.product_id')
                    ->whereColumn('li2.order_id', 'o.id')
                    ->where('p2.business_unit_id', $khaasId);
            });
        }

        // Sep-2026 — each order's PROFIT contribution rides along as `earned`, so
        // this list adds up to the day's bar on the chart (which uses the same
        // rule). The invoice face stays in `total_price` for the paid label.
        \App\Services\FIN\ProfitRevenueSql::join($q, 'o');
        \App\Services\FIN\ProfitRevenueSql::joinDelivered($q, 'o');
        $earnedExpr = \App\Services\FIN\ProfitRevenueSql::revenue(
            'o',
            \App\Services\FIN\ProfitRevenueSql::DEL_ALIAS . '.first_delivered_at',
            \App\Services\FIN\ProfitRevenueSql::BAL_ALIAS,
            $unit !== self::UNIT_QB   // Qurbani keeps its tips (they never enter the fund)
        );

        $rows = $q->select(
            'o.id', 'o.order_number', 'o.total_price', 'o.total_paid', 'o.payment_method',
            'o.customer_id', 'c.first_name', 'c.last_name', 'c.customer_type'
        )->selectRaw("$earnedExpr as earned")
         ->orderBy('o.order_number')->limit(500)->get();

        // kg per order (unit-aware).
        $orderIds = $rows->pluck('id')->all();
        $kgByOrder = [];
        if (!empty($orderIds)) {
            $lq = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->whereIn('li.order_id', $orderIds);
            if ($unit === self::UNIT_KH && $khaasId) {
                $lq->where('p.business_unit_id', $khaasId);
            } elseif ($unit === self::UNIT_NF && $khaasId) {
                $lq->where(function ($w) use ($khaasId) {
                    $w->where('p.business_unit_id', '!=', $khaasId)->orWhereNull('p.business_unit_id');
                });
            }
            $kgByOrder = $lq->select('li.order_id', DB::raw('SUM(li.quantity) as kg'))
                ->groupBy('li.order_id')->pluck('kg', 'li.order_id')->all();
        }

        return $rows->map(function ($r) use ($kgByOrder) {
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            return [
                'order_number' => $r->order_number,
                'customer'     => $name !== '' ? $name : 'Walk-in',
                'type'         => $r->customer_type ?? 'regular',
                'kg'           => round((float) ($kgByOrder[$r->id] ?? 0), 1),
                'amount'       => round((float) $r->earned),
                // The invoice face, only when it differs from what was earned
                // (a tip taken out, or balance added back) — so the row can
                // explain itself instead of looking wrong next to the invoice.
                'invoice'      => round((float) $r->total_price) !== round((float) $r->earned)
                                    ? round((float) $r->total_price) : null,
                'paid'         => $this->paymentLabel($r->payment_method, (float) $r->total_paid, (float) $r->total_price),
            ];
        })->all();
    }

    /** Vendor purchases → Level 1: per-vendor for the unit + month. */
    public function vendorBreakdown(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return []; // Qurbani has no vendors.
        }
        return Cache::remember("hq_d_vendors_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->vendorBreakdownCompute($unit, $year, $month));
    }

    private function vendorBreakdownCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'l.to_account_id')
            ->leftJoin('t_fin_vendors as v', 'v.account_id', '=', 'a.id')
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end]);
        QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
        $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');

        $rows = $q->select(
            DB::raw('COALESCE(v.vendor_name, a.account_name, "Unknown") as vendor'),
            'a.id as account_id',
            DB::raw('COUNT(*) as bills'),
            DB::raw('SUM(l.amount) as purchases'),
            DB::raw('MAX(a.current_balance) as balance')
        )->groupBy('vendor', 'a.id')->orderByDesc('purchases')->get();

        return $rows->map(fn ($r) => [
            'vendor'     => $r->vendor,
            'account_id' => (int) $r->account_id,
            'bills'      => (int) $r->bills,
            'purchases'  => round((float) $r->purchases),
            'balance'    => round((float) $r->balance),
        ])->all();
    }

    /** Vendor purchases → Level 2: the bills for one vendor account + month. */
    public function vendorDetail(int $accountId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth()->endOfDay();
        return DB::table('t_fin_ledger as l')
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->where('l.to_account_id', $accountId)
            ->whereBetween('l.transaction_date', [$start, $end])
            ->orderBy('l.transaction_date')
            ->get(['l.transaction_date as date', 'l.description', 'l.amount'])
            ->map(fn ($r) => [
                'date'   => Carbon::parse($r->date)->format('M d'),
                'note'   => $r->description ?: '—',
                'amount' => round((float) $r->amount),
            ])->all();
    }

    /**
     * SQL expression for an expense's MEANINGFUL category — the request's
     * `expense_category` (e.g. "Staff Salaries" / "Petrol" / "Rent" / "Food"),
     * the same field the Finance expense screen groups by. Falls back to the
     * request TYPE then "Uncategorised". Requires t_req_master aliased `r` and
     * t_req_category aliased `rc` in the query. (The request type — rc.category_name
     * — is almost always just "Expense Reimbursement", i.e. a useless one-bucket
     * breakdown; expense_category is the real split shown to the owner.)
     */
    private static function expenseCategoryExpr(): string
    {
        return "COALESCE(NULLIF(TRIM(r.expense_category), ''), rc.category_name, 'Uncategorised')";
    }

    /** Expenses → Level 1: per-category for the unit + month. */
    public function expenseByCategory(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_expcat_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->expenseByCategoryCompute($unit, $year, $month));
    }

    private function expenseByCategoryCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_req_master as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('t_req_category as rc', 'rc.id', '=', 'r.category_id')
            ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end]);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');
        }

        $rows = $q->select(
            DB::raw(self::expenseCategoryExpr() . ' as category'),
            DB::raw('COUNT(*) as entries'),
            DB::raw('SUM(l.amount) as amount')
        )->groupBy('category')->orderByDesc('amount')->get();

        $total = $rows->sum('amount') ?: 1;
        return $rows->map(fn ($r) => [
            'category' => $r->category,
            'entries'  => (int) $r->entries,
            'amount'   => round((float) $r->amount),
            'pct'      => round((float) $r->amount / $total * 100, 1),
        ])->all();
    }

    /** Expenses → Level 2: entries in one category for the unit + month. */
    public function expenseDetail(string $unit, int $year, int $month, string $category): array
    {
        $unit = $this->normalizeUnit($unit);
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_req_master as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('t_req_category as rc', 'rc.id', '=', 'r.category_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
            ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end])
            ->where(DB::raw(self::expenseCategoryExpr()), $category);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');
        }

        return $q->orderBy('l.transaction_date')->limit(500)
            ->get(['l.transaction_date as date', 'l.description', 'l.amount', 'u.fullname as who'])
            ->map(fn ($r) => [
                'date'   => Carbon::parse($r->date)->format('M d'),
                'who'    => $r->who ?: 'System',
                'note'   => $r->description ?: '—',
                'amount' => round((float) $r->amount),
            ])->all();
    }

    /** Salaries → Level 1: per-employee salaries paid in the unit + month (P&L "Salaries" drill). */
    public function salaryByEmployee(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return []; // salaries are never a Qurbani cost
        }
        return Cache::remember("hq_d_salary_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->salaryByEmployeeCompute($unit, $year, $month));
    }

    /**
     * ⭐ ONE ROW PER EMPLOYEE (Sep-2026, owner ruling). The salary engine emits one row per
     * PAYMENT — a person paid in two instalments, or paid plus holding an unrecovered
     * advance, appeared two or three times (Aug-2026: 41 rows for 12 people). Level 1 is
     * the summary you scan, so it groups here and the individual payments moved to
     * Level 2 (salaryEmployeeDetail), matching every other drill on this page.
     * The total is unchanged — grouping only folds rows together.
     */
    private function salaryByEmployeeCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);
        $khaasId = (int) ($this->khaasBusinessUnitId() ?: -1);
        $nfName = 'Nizami Farms'; $khName = 'Khaas · Frozen';
        $groups = [];

        // Same engine as the headline Salaries figure, so the drill always sums to it.
        // Includes advances already paid out but not yet recovered, labelled as such so an
        // unpaid month explains itself instead of showing a bare number.
        foreach ((new \App\Services\HR\SalaryCostService())->detailForWindow($start, $end, $khaasId) as $r) {
            if ($unit === self::UNIT_KH && !$r['is_khaas']) { continue; }
            if ($unit === self::UNIT_NF && $r['is_khaas']) { continue; }

            // Key on user_id; fall back to the name so a legacy slip with no user still gets
            // its own row instead of merging every such slip into one.
            $key = $r['user_id'] !== null ? 'u' . $r['user_id'] : 'n:' . $r['employee'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'user_id'  => $r['user_id'],
                    'name'     => $r['employee'],
                    'unit'     => $r['is_khaas'] ? $khName : $nfName,
                    'payments' => 0,
                    'amount'   => 0.0,
                    'advances' => 0,
                ];
            }
            $groups[$key]['payments']++;
            $groups[$key]['amount'] += (float) $r['amount'];
            if (($r['kind'] ?? '') === 'advance_open') { $groups[$key]['advances']++; }
        }

        $out = [];
        foreach ($groups as $g) {
            // Keep the wording the ungrouped list had: every entry an advance = the salary
            // itself is still unpaid; only some = a paid salary plus an advance still open.
            $suffix = '';
            if ($g['advances'] > 0) {
                $suffix = $g['advances'] === $g['payments']
                    ? ' · advance — salary not paid yet'
                    : ' · includes an advance not yet recovered';
            }
            $out[] = [
                'user_id'      => $g['user_id'],
                'employee'     => $g['name'] . $suffix,
                'employee_key' => $g['name'],   // clean name — Level 2 lookup + panel title
                'unit'         => $g['unit'],
                'payments'     => $g['payments'],
                'amount'       => round($g['amount']),
            ];
        }
        usort($out, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        return $out;
    }

    /**
     * Salaries → Level 2: the individual payments behind one employee's grouped row.
     * Reads the SAME engine as Level 1, so the entries always add up to the row clicked.
     * Not cached — Level 1 is, and this is one person's short list.
     */
    public function salaryEmployeeDetail(string $unit, int $year, int $month, ?int $userId, string $employee = ''): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return []; // salaries are never a Qurbani cost
        }
        [$start, $end] = $this->period($unit, $year, $month);
        $khaasId = (int) ($this->khaasBusinessUnitId() ?: -1);
        $out = [];

        foreach ((new \App\Services\HR\SalaryCostService())->detailForWindow($start, $end, $khaasId) as $r) {
            if ($unit === self::UNIT_KH && !$r['is_khaas']) { continue; }
            if ($unit === self::UNIT_NF && $r['is_khaas']) { continue; }
            $isMine = $userId !== null
                ? $r['user_id'] === $userId
                : ($r['user_id'] === null && $r['employee'] === $employee);
            if (!$isMine) { continue; }

            $out[] = [
                'raw_date' => (string) ($r['date'] ?? ''),
                'kind'     => (string) ($r['kind'] ?? ''),
                'amount'   => round((float) $r['amount']),
            ];
        }

        // Oldest first, biggest first within a day — reads like a statement.
        usort($out, function ($a, $b) {
            return ($a['raw_date'] <=> $b['raw_date']) ?: ($b['amount'] <=> $a['amount']);
        });

        return array_map(fn ($r) => [
            'date'   => $r['raw_date'] !== '' ? Carbon::parse($r['raw_date'])->format('M d') : '—',
            'type'   => match ($r['kind']) {
                'advance_open' => 'Advance — salary not paid yet',
                'slip'         => 'Salary slip (legacy)',
                default        => 'Salary payment',
            },
            'amount' => $r['amount'],
        ], $out);
    }


    /** Customers → Level 1: customers who ordered in the unit + month. */
    public function customersList(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_cust_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->customersListCompute($unit, $year, $month));
    }

    private function customersListCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();

        $q = $this->deliveredBase($start, $end, $qmode)
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id');
        if ($unit === self::UNIT_KH && $khaasId) {
            $q->whereExists(function ($sub) use ($khaasId) {
                $sub->select(DB::raw(1))->from('t_crm_prod_order_line_item as li2')
                    ->join('t_crm_prod_product as p2', 'p2.id', '=', 'li2.product_id')
                    ->whereColumn('li2.order_id', 'o.id')->where('p2.business_unit_id', $khaasId);
            });
        }

        return $q->select(
            'o.customer_id',
            DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
            DB::raw('MAX(c.customer_type) as type'),
            DB::raw('COUNT(DISTINCT o.id) as orders'),
            DB::raw('SUM(o.total_price) as spent'),
            DB::raw('MIN(c.first_order_date) as first_delivery')
        )->groupBy('o.customer_id')->orderByDesc('spent')->limit(500)->get()
            ->map(function ($r) use ($start, $end) {
                $isNew = $r->first_delivery && Carbon::parse($r->first_delivery)->between($start, $end);
                return [
                    'customer'  => $r->name ?: 'Walk-in',
                    'type'      => $r->type ?: 'regular',
                    'orders'    => (int) $r->orders,
                    'spent'     => round((float) $r->spent),
                    'first'     => $r->first_delivery ? Carbon::parse($r->first_delivery)->format('M y') : '—',
                    'is_new'    => (bool) $isNew,
                ];
            })->all();
    }

    /**
     * Working capital → receivables L1 SUMMARY: one row per receivable TYPE
     * (Regular / Shops / Riders) with the total and a 30-day aging split
     * (fresh ≤30d vs pending >30d). Regular & Shops drill to their customer list
     * (receivablesByType); Riders is a single settlement balance (no_drill). For
     * Qurbani it is the single "season unpaid" row. Totals reconcile with the
     * working-capital Receivables card because the per-type bases here are
     * identical to regularPendingReceivable / shopOutstandingReceivable /
     * qurbaniSeasonReceivable.
     */
    public function receivablesSummary(string $unit = self::UNIT_NF): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return [$this->receivableSummaryRow('qurbani', 'Qurbani season · unpaid', $this->receivableRows($unit, 'qurbani'))];
        }
        $rider = round(AccountModel::getTotalEmployeeCashCalculatedBalance(), 0);
        return [
            $this->receivableSummaryRow('regular', 'Regular · pending approvals', $this->receivableRows($unit, 'regular')),
            $this->receivableSummaryRow('shop', 'Shops · outstanding', $this->receivableRows($unit, 'shop')),
            [
                'type' => 'rider', 'label' => 'Riders · cash to settle',
                'customers' => null, 'total' => $rider, 'fresh' => null, 'aging' => null,
                'no_drill' => true,
            ],
        ];
    }

    /** Fold a type's customer rows into a summary line (count + fresh/aging split). */
    private function receivableSummaryRow(string $type, string $label, array $rows): array
    {
        $total = 0.0; $aging = 0.0;
        foreach ($rows as $r) { $total += $r['amount']; $aging += $r['aging']; }
        return [
            'type'      => $type,
            'label'     => $label,
            'customers' => count($rows),
            'total'     => round($total),
            'fresh'     => round($total - $aging),
            'aging'     => round($aging),
            'no_drill'  => count($rows) === 0,
        ];
    }

    /** Working capital → receivables L2: the customers behind ONE type. */
    public function receivablesByType(string $unit, string $type): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            $type = 'qurbani';
        } elseif (!in_array($type, ['regular', 'shop'], true)) {
            $type = 'regular';
        }
        return $this->receivableRows($unit, $type);
    }

    /**
     * Per-customer receivable rows for ONE type, each carrying a 30-day `aging`
     * amount (invoice/order-level, so the summary's fresh/pending split is exact).
     * The cutoff is a self-formatted datetime string, so direct interpolation is
     * injection-safe. Bases mirror the working-capital card + ONLINE APPROVALS.
     */
    private function receivableRows(string $unit, string $type): array
    {
        $now = Carbon::now();
        $cut = $now->copy()->subDays(30)->toDateTimeString();

        if ($type === 'qurbani') {
            $prefix = 'QUR' . $now->format('y') . '%';
            $rows = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.order_number', 'like', $prefix)
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->whereColumn('o.total_paid', '<', 'o.total_price')
                ->select(
                    DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                    DB::raw('COUNT(*) as invoices'),
                    DB::raw('SUM(o.total_price - COALESCE(o.total_paid,0)) as outstanding'),
                    DB::raw("SUM(CASE WHEN o.order_date < '$cut' THEN (o.total_price - COALESCE(o.total_paid,0)) ELSE 0 END) as aging"),
                    DB::raw('MIN(o.order_date) as oldest')
                )
                ->groupBy(DB::raw('COALESCE(o.customer_id, 0)'))
                ->orderByDesc('outstanding')->limit(200)->get();
        } elseif ($type === 'shop') {
            $rows = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('c.customer_type', 'shop')
                ->whereIn('o.payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
                ->where('o.order_status', 'delivered')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))->from('t_fin_ledger as l')
                        ->whereColumn('l.order_id', 'o.id')
                        ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                        ->whereIn('l.approval_status', [
                            LedgerModel::STATUS_APPROVED,
                            LedgerModel::STATUS_PENDING_L2,
                        ]);
                })
                ->select(
                    DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                    DB::raw('COUNT(*) as invoices'),
                    DB::raw('SUM(GREATEST(o.total_price - COALESCE(o.total_paid,0), 0)) as outstanding'),
                    DB::raw("SUM(CASE WHEN o.order_date < '$cut' THEN GREATEST(o.total_price - COALESCE(o.total_paid,0), 0) ELSE 0 END) as aging"),
                    DB::raw('MIN(o.order_date) as oldest')
                )
                ->groupBy('o.customer_id')
                ->havingRaw('outstanding > 0')
                ->orderByDesc('outstanding')->limit(200)->get();
        } else { // regular
            $rows = DB::table('t_fin_ledger as l')
                ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->whereIn('l.approval_status', [
                    LedgerModel::STATUS_PENDING,
                    LedgerModel::STATUS_PENDING_L1,
                    LedgerModel::STATUS_PENDING_L2,
                ])
                ->whereNull('l.request_id')
                ->where('l.mode', LedgerModel::MODE_ONLINE)
                ->where(function ($w) {
                    $w->whereNull('c.customer_type')->orWhere('c.customer_type', '!=', 'shop');
                })
                ->select(
                    DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                    DB::raw('COUNT(*) as invoices'),
                    DB::raw('SUM(l.amount) as outstanding'),
                    DB::raw("SUM(CASE WHEN l.transaction_date < '$cut' THEN l.amount ELSE 0 END) as aging"),
                    DB::raw('MIN(l.transaction_date) as oldest')
                )
                ->groupBy(DB::raw('COALESCE(o.customer_id, 0)'))
                ->orderByDesc('outstanding')->limit(200)->get();
        }

        return $rows->map(function ($r) use ($type, $now) {
            $row = $this->recvRow($r->name, $type, (float) $r->outstanding, $r->oldest, $now, (int) $r->invoices);
            $row['aging'] = round((float) $r->aging);
            return $row;
        })->all();
    }

    private function recvRow(?string $name, string $type, float $amount, $oldest, Carbon $now, int $invoices): array
    {
        return [
            'who'      => ($name !== null && trim($name) !== '') ? trim($name) : 'Customer',
            'type'     => $type,
            'invoices' => $invoices,
            'amount'   => round($amount),
            'oldest'   => $oldest ? Carbon::parse($oldest)->format('M d') : '—',
            'age'      => $oldest ? (int) Carbon::parse($oldest)->diffInDays($now) : 0,
        ];
    }

    /** Working capital → payables by vendor (who we owe). */
    public function payablesByVendor(): array
    {
        return DB::table('t_fin_accounts as a')
            ->leftJoin('t_fin_vendors as v', 'v.account_id', '=', 'a.id')
            ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
            ->where('a.account_category', AccountModel::CATEGORY_VENDOR_PAYABLE)
            ->where('a.is_active', 1)
            ->where('a.current_balance', '>', 0)
            ->orderByDesc('a.current_balance')->limit(300)
            ->get([
                DB::raw('COALESCE(v.vendor_name, a.account_name) as vendor'),
                DB::raw('COALESCE(bu.name, "Nizami Farms") as unit'),
                'a.current_balance as balance',
            ])
            ->map(fn ($r) => [
                'vendor'  => $r->vendor,
                'unit'    => $r->unit,
                'balance' => round((float) $r->balance),
            ])->all();
    }

    private function paymentLabel(?string $method, float $paid, float $total): string
    {
        if ($paid >= $total && $total > 0) {
            return in_array($method, ['cash', 'cash_on_delivery'], true) ? 'cash' : 'online';
        }
        if ($paid > 0) return 'partial';
        return in_array($method, ['cash', 'cash_on_delivery'], true) ? 'cash (due)' : 'unpaid';
    }

    // =====================================================================
    //  CORE CLOSING COMPUTATION (one unit, one window)
    // =====================================================================

    /**
     * Public entry point for the Ledger Hub Overview. Returns the unit P&L
     * (revenue / vendor_purchases / expenses / net_profit) over an ARBITRARY
     * window using the SAME computation as the HQ closing, so the Hub's Sales
     * card reconciles with the HQ dashboard by construction rather than by a
     * parallel formula. Read-only; adds no behavior to HQ.
     *   $unit ∈ {UNIT_ALL='all', UNIT_NF='nf', UNIT_KH='kh' (Frozen line-item split),
     *            UNIT_QB='qb' (Qurbani: revenue − qurbani-category expenses, no vendor)}.
     */
    public function unitPnl(string $unit, Carbon $start, Carbon $end): array
    {
        return $this->computeClosing($unit, $start, $end);
    }

    private function computeClosing(string $unit, Carbon $start, Carbon $end, ?Carbon $ledgerEnd = null): array
    {
        if ($unit === self::UNIT_QB) {
            return $this->qurbaniClosing($start, $end);
        }

        // All the delivered-order metrics for the window in ONE cached bundle
        // (2 aggregate queries), shared by every non-Qurbani unit + the trend.
        // $ledgerEnd (when given) widens ONLY the cost side (vendor / expense /
        // asset ledger rows) to the full calendar month, so the HQ monthly view
        // counts the whole month like the Reports tab even mid-month; delivered
        // revenue still stops at the window end. Null = exact window (the Hub's
        // unitPnl passes explicit ranges and must stay bounded to them).
        $r = $this->rawWindow($start, $end, $ledgerEnd);

        switch ($unit) {
            case self::UNIT_KH:
                $revenue = $r['rev_kh']; $orders = $r['ord_kh']; $kg = $r['kg_kh'];
                $pcs = $r['pcs_kh'];
                $customers = $r['cust_kh']; $new = $r['new_kh'];
                $vendor = $r['vendor_kh']; $expense = $r['expense_kh']; $salary = $r['salary_kh'];
                break;
            case self::UNIT_NF:
                // NF = regular business minus the Khaas slice. Delivery charges &
                // order-level discounts live in the order total, so they land in
                // NF here (owner rule).
                $revenue = $r['rev_all'] - $r['rev_kh']; $orders = $r['ord_nf'];
                $kg = max($r['kg_all'] - $r['kg_kh'], 0);
                $pcs = max($r['pcs_all'] - $r['pcs_kh'], 0);
                $customers = $r['cust_nf']; $new = $r['new_nf'];
                $vendor = $r['vendor_nf']; $expense = $r['expense_nf']; $salary = $r['salary_nf'];
                break;
            default: // UNIT_ALL
                $revenue = $r['rev_all']; $orders = $r['ord_all']; $kg = $r['kg_all'];
                $pcs = $r['pcs_all'];
                $customers = $r['cust_all']; $new = $r['new_all'];
                $vendor = $r['vendor_all']; $expense = $r['expense_all']; $salary = $r['salary_all'];
                break;
        }

        $grossProfit = $revenue - $vendor;
        // Salaries are a real operating cost, shown as their own P&L line (not folded
        // into "Expenses", which stays the request-based expense ledger).
        $netProfit   = $grossProfit - $expense - $salary;
        $assets      = $this->monthAssetPurchases($unit, $start, $ledgerEnd ?? $end);
        $supplies    = $this->monthSupplyPurchases($unit, $start, $ledgerEnd ?? $end);

        return [
            'revenue'          => round($revenue, 0),
            // Sep-2026 — what the revenue figure already absorbed, for the tile's
            // sub-line. Both are whole-business numbers (neither is a line item,
            // so neither splits by unit); shown only on the All / NF views.
            'balance_used'     => round($r['bal_used'] ?? 0, 0),
            'tips_excluded'    => round($r['tips_out'] ?? 0, 0),
            'orders'           => (int) $orders,
            'kg'               => round($kg, 0),
            'pieces'           => round($pcs, 0),
            'customers'        => (int) $customers,
            'new_customers'    => (int) $new,
            'vendor_purchases' => round($vendor, 0),
            'gross_profit'     => round($grossProfit, 0),
            'expenses'         => round($expense, 0),
            'salaries'         => round($salary, 0),
            'net_profit'       => round($netProfit, 0),
            // Capital spending in the month — NOT part of the P&L (equipment
            // becomes property, not an expense). Surfaced as its own box beside
            // net profit so "assets bought this month" reconciles with Reports.
            'asset_purchases'       => round($assets['amount'], 0),
            'asset_purchases_count' => $assets['count'],
            // 📦 Packaging stock bought this month — money out, but NOT part of the P&L
            // (it becomes an expense packet by packet, as it is used). Own box beside
            // asset purchases so the month's cash still reconciles.
            'supplies_bought'       => round($supplies['amount'], 0),
            'supplies_bought_count' => $supplies['count'],
        ];
    }

    /**
     * Asset purchases (capital spending) in the window. Deliberately NOT part of
     * revenue − vendor − expenses; shown as its own box on the HQ P&L so it
     * reconciles with the Reports tab's "Assets" figure. Uses the same ledger end
     * as vendor/expense (full calendar month for the monthly view). Approved /
     * pending_l2 (HQ posting convention); scoped to the selected unit by
     * business_unit_id. Qurbani is not filtered out — asset purchases are never
     * Qurbani, and Reports counts them the same way.
     *
     * @return array{amount:float,count:int}
     */
    private function monthAssetPurchases(string $unit, Carbon $start, Carbon $end): array
    {
        $row = $this->assetPurchaseBase($unit, $start, $end)
            ->selectRaw('COALESCE(SUM(l.amount),0) amt, COUNT(*) n')->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    /**
     * 📦 Storage stock bought in the window. Money left the accounts, but it is NOT an
     * expense yet — it becomes one, one packet at a time, as the stock is used. So it is
     * deliberately outside revenue − vendor − expenses, and gets its own box beside
     * asset purchases, exactly like capital spending. Same shape and same unit scoping.
     */
    private function monthSupplyPurchases(string $unit, Carbon $start, Carbon $end): array
    {
        $row = $this->ledgerTypeBase(LedgerModel::TYPE_SUPPLY_PURCHASE, $unit, $start, $end)
            ->selectRaw('COALESCE(SUM(l.amount),0) amt, COUNT(*) n')->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    /** Shared base for the asset-purchase total + its drill list. */
    private function assetPurchaseBase(string $unit, Carbon $start, Carbon $end)
    {
        return $this->ledgerTypeBase('asset_purchase', $unit, $start, $end);
    }

    /** One ledger type, one window, scoped to the selected unit. */
    private function ledgerTypeBase(string $type, string $unit, Carbon $start, Carbon $end)
    {
        $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
        $q = DB::table('t_fin_ledger as l')
            ->where('l.transaction_type', $type)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end]);
        if ($unit === self::UNIT_NF) {
            $q->where(function ($w) use ($kh) {
                $w->where('l.business_unit_id', '<>', $kh)->orWhereNull('l.business_unit_id');
            });
        } elseif ($unit === self::UNIT_KH) {
            $q->where('l.business_unit_id', $kh);
        }
        return $q;
    }

    /**
     * Assets-bought box → drill: what was actually bought this month. Joins the
     * assets register (when the purchase created an asset record) so the owner
     * sees the asset name, not just the ledger description.
     */
    public function assetPurchasesList(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        try {
            [$start, $end] = $this->period($unit, $year, $month);
            $lEnd = $this->ledgerEndFor($unit, $end) ?? $end;
            return $this->assetPurchaseBase($unit, $start, $lEnd)
                ->leftJoin('t_fin_assets as a', 'a.ledger_transaction_id', '=', 'l.id')
                ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'l.business_unit_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
                ->orderByDesc('l.transaction_date')
                ->limit(300)
                ->get([
                    'l.transaction_date', 'l.amount', 'l.description',
                    'a.asset_name', 'a.location',
                    DB::raw('COALESCE(bu.name, "Nizami Farms") as unit'),
                    'u.fullname as who',
                ])
                ->map(fn ($r) => [
                    'asset'  => $r->asset_name ?: ($r->description ?: 'Asset purchase'),
                    'unit'   => $r->unit,
                    'bought' => $r->transaction_date ? Carbon::parse($r->transaction_date)->format('M d') : '—',
                    'who'    => $r->who ?: '—',
                    'amount' => round((float) $r->amount),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =====================================================================
    //  PRODUCTS  (Phase 3, Jul-2026) — what sells, in what quantity, by category
    // =====================================================================

    /**
     * product_type → display category. The catalog's product_type mixes real
     * categories (Beef, Chicken) with cut-level types (Back Chops, Raan &
     * Dasti…), so the tab groups through this map WITHOUT touching the catalog.
     * Unmapped types pass through under their own name — a new type stays
     * visible instead of hiding in Others — and every drill shows the raw type,
     * so a wrong bucket is easy to spot and a one-line fix here (owner review
     * pending — ruling P-R1). Keys are entity-DECODED ("&", not "&amp;").
     */
    private const CATEGORY_ROLLUP = [
        'Beef' => 'Beef', 'Boneless LEAN Beef' => 'Beef',
        'Bihari & Pasanday' => 'Beef', 'Steaks' => 'Beef',
        'Mutton' => 'Mutton', 'Lean Whole/Half Bakra' => 'Mutton',
        'Whole/Half Bakra' => 'Mutton', 'Raan & Dasti Lean' => 'Mutton',
        'Raan & Dasti' => 'Mutton', 'Raan Choice Lean' => 'Mutton',
        'Raan Choice' => 'Mutton', 'Back Chops (Puth)' => 'Mutton',
        'Front Chops' => 'Mutton', 'Boneless' => 'Mutton', 'Lean Meat' => 'Mutton',
        'Whole LEAN Meat Cut' => 'Mutton', 'Whole Meat Cut' => 'Mutton',
        'Mix' => 'Mutton', 'Mix Lean' => 'Mutton', 'Lamb' => 'Mutton',
        'Chicken' => 'Chicken', 'Boneless LEAN Chicken' => 'Chicken',
        'Boneless Chicken' => 'Chicken', 'Thigh LEAN' => 'Chicken',
        'Thigh' => 'Chicken', 'LEAN Drumsticks' => 'Chicken',
        'Drumsticks' => 'Chicken', 'Wings' => 'Chicken',
        'Minced (Qeema)' => 'Minced (Qeema)',
        'Paaye' => 'Paaye',
        'Khaas' => 'Khaas · Frozen', 'Table Talk' => 'Khaas · Frozen',
        'Sadqa' => 'Sadqa & Aqeeqa',
        'Dog Food' => 'Dog Food',
        'Others' => 'Others',
        'Qurbani 2026' => 'Qurbani',
        // "Qurbani 2023" is a STALE type shared by two very different things:
        // the Aqeeqa listings — which are what actually sells on regular orders
        // (verified Jul-2026) — and three dead 2023 Qurbani listings whose only
        // orders since are cancelled, so they never reach this tab (delivered
        // basis). Hence Aqeeqa. Never classify Qurbani by this type.
        'Qurbani 2023' => 'Sadqa & Aqeeqa',
        'Goat Qurbani Day 1' => 'Qurbani', 'Goat Qurbani Day 2' => 'Qurbani',
        'Goat Qurbani Day 3' => 'Qurbani',
    ];

    private const CAT_UNLINKED = 'Old catalog · unlinked';

    private function rollupCategory(?string $type): string
    {
        $t = html_entity_decode(trim((string) $type), ENT_QUOTES);
        if ($t === '') return 'Others';
        return self::CATEGORY_ROLLUP[$t] ?? $t;
    }

    /** Line-level unit filter (same rule as the closing: NULL BU = NF). */
    private function applyLineUnit($q, string $unit): void
    {
        $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
        if ($unit === self::UNIT_KH) {
            $q->where('p.business_unit_id', $kh);
        } elseif ($unit === self::UNIT_NF) {
            $q->where(function ($w) use ($kh) {
                $w->where('p.business_unit_id', '<>', $kh)->orWhereNull('p.business_unit_id');
            });
        }
    }

    /**
     * Per-product aggregates for one window (delivered, non-Shopify, Qurbani
     * excluded — same base as the closing). Products whose catalog link is
     * broken (pre-Mar-2026 Shopify-era ids) land in the "Old catalog ·
     * unlinked" bucket rather than being silently dropped.
     */
    private function productWindowAggregates(string $unit, Carbon $start, Carbon $end): array
    {
        $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
        $pieceCase = self::pieceCaseSql($kh);
        $q = $this->deliveredLines($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE);
        $this->applyLineUnit($q, $unit);
        $rows = $q->groupBy('li.product_id')
            ->selectRaw("li.product_id pid,
                MAX(COALESCE(p.title, li.name)) pname,
                MAX(p.product_type) ptype,
                MAX(p.id IS NOT NULL) linked,
                COALESCE(SUM(li.line_total),0) rev,
                COALESCE(SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END),0) kg,
                COALESCE(SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END),0) pcs,
                COUNT(DISTINCT o.id) ords")
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->pid] = [
                'pid'    => (int) $r->pid,
                'name'   => html_entity_decode((string) $r->pname, ENT_QUOTES),
                'type'   => $r->linked ? html_entity_decode((string) $r->ptype, ENT_QUOTES) : '—',
                'cat'    => $r->linked ? $this->rollupCategory($r->ptype) : self::CAT_UNLINKED,
                'linked' => (bool) $r->linked,
                'rev'    => (float) $r->rev,
                'kg'     => (float) $r->kg,
                'pcs'    => (float) $r->pcs,
                'ords'   => (int) $r->ords,
            ];
        }
        return $out;
    }

    /**
     * Distinct order ids per CATEGORY for a window (order × type pairs rolled
     * up in PHP — an order spanning two types of one category counts once).
     * ['cats' => [cat => orderCount], 'total' => distinct orders overall].
     */
    private function categoryOrderCounts(string $unit, Carbon $start, Carbon $end): array
    {
        $q = $this->deliveredLines($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE);
        $this->applyLineUnit($q, $unit);
        $pairs = $q->selectRaw('DISTINCT o.id oid, p.product_type ptype, (p.id IS NOT NULL) linked')->get();
        $cats = []; $all = [];
        foreach ($pairs as $p) {
            $cat = $p->linked ? $this->rollupCategory($p->ptype) : self::CAT_UNLINKED;
            $cats[$cat][$p->oid] = true;
            $all[$p->oid] = true;
        }
        return [
            'cats'  => array_map('count', $cats),
            'total' => count($all),
        ];
    }

    /**
     * The Products tab payload: categories (revenue / qty / share / MoM /
     * penetration), top products, movers, first-time products, dead listings.
     * Delivered basis, same canonical rules as the closing; Qurbani excluded
     * (it has its own view). Qurbani unit → marker payload, tab shows a note.
     */
    public function products(string $unit, int $year, int $month, bool $fresh = false): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return ['season' => true];
        }
        $key = "hq_products_{$unit}_{$year}_{$month}";
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, $this->ttlFor($unit, $year, $month), function () use ($unit, $year, $month) {
            [$start, $end, $label, $isOpen] = $this->period($unit, $year, $month);
            [$pStart, $pEnd] = $this->previousPeriod($unit, $year, $month);

            $cur  = $this->productWindowAggregates($unit, $start, $end);
            $prev = $this->productWindowAggregates($unit, $pStart, $pEnd);
            $pen  = $this->categoryOrderCounts($unit, $start, $end);

            // ---- categories ----
            $cats = [];
            foreach ($cur as $p) {
                $c = $p['cat'];
                if (!isset($cats[$c])) $cats[$c] = ['label' => $c, 'rev' => 0.0, 'kg' => 0.0, 'pcs' => 0.0, 'products' => 0, 'prev' => 0.0, 'types' => []];
                $cats[$c]['rev'] += $p['rev'];
                $cats[$c]['kg']  += $p['kg'];
                $cats[$c]['pcs'] += $p['pcs'];
                $cats[$c]['products']++;
                if ($p['type'] !== '—') $cats[$c]['types'][$p['type']] = true;
            }
            foreach ($prev as $p) {
                $c = $p['cat'];
                if (!isset($cats[$c])) continue; // vanished categories aren't shown; movers catch the products
                $cats[$c]['prev'] += $p['rev'];
            }
            $totalRev = array_sum(array_column($cats, 'rev'));
            $catsOut = array_values(array_map(function ($c) use ($totalRev, $pen) {
                return [
                    'label'       => $c['label'],
                    'revenue'     => round($c['rev']),
                    'share'       => $totalRev > 0 ? round($c['rev'] / $totalRev * 100, 1) : 0,
                    'kg'          => round($c['kg'], 1),
                    'pcs'         => round($c['pcs']),
                    'products'    => $c['products'],
                    'prev'        => round($c['prev']),
                    'orders'      => $pen['cats'][$c['label']] ?? 0,
                    'penetration' => $pen['total'] > 0 ? round(($pen['cats'][$c['label']] ?? 0) / $pen['total'] * 100, 1) : 0,
                    'types'       => array_keys($c['types']),
                ];
            }, $cats));
            usort($catsOut, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

            // ---- top products (current window) ----
            $prods = array_values($cur);
            usort($prods, fn ($a, $b) => $b['rev'] <=> $a['rev']);
            $top = array_map(fn ($p) => $this->productRow($p, $prev[$p['pid']]['rev'] ?? null), array_slice($prods, 0, 10));

            // ---- movers: biggest revenue swings vs previous period (linked only) ----
            $deltas = [];
            $pids = array_unique(array_merge(array_keys($cur), array_keys($prev)));
            foreach ($pids as $pid) {
                $c = $cur[$pid] ?? null; $pv = $prev[$pid] ?? null;
                if (!($c['linked'] ?? $pv['linked'] ?? false)) continue;
                $d = ($c['rev'] ?? 0) - ($pv['rev'] ?? 0);
                if (abs($d) < 5000) continue; // noise floor
                $deltas[] = ['name' => $c['name'] ?? $pv['name'], 'delta' => round($d),
                             'now' => round($c['rev'] ?? 0), 'was' => round($pv['rev'] ?? 0)];
            }
            usort($deltas, fn ($a, $b) => $b['delta'] <=> $a['delta']);
            $movers = [
                'up'   => array_values(array_filter(array_slice($deltas, 0, 5), fn ($m) => $m['delta'] > 0)),
                'down' => array_values(array_filter(array_slice(array_reverse($deltas), 0, 5), fn ($m) => $m['delta'] < 0)),
            ];

            // ---- first-time + dead listings (by order date; qurbani products excluded) ----
            [$freshProducts, $dead] = $this->productLifecycles($start, $end);

            // ---- data-quality chip for pre-Mar-2026 months ----
            $unlinkedRev = $cats[self::CAT_UNLINKED]['rev'] ?? 0.0;

            return [
                'period_label' => $label,
                'is_open'      => $isOpen,
                'window'       => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'total'        => ['revenue' => round($totalRev), 'orders' => $pen['total']],
                'categories'   => $catsOut,
                'top'          => $top,
                'movers'       => $movers,
                'fresh_products' => $freshProducts,
                'dead'         => $dead,
                'quality'      => [
                    'unlinked_rs'  => round($unlinkedRev),
                    'unlinked_pct' => $totalRev > 0 ? round($unlinkedRev / $totalRev * 100, 1) : 0,
                ],
            ];
        });
    }

    /** Shared row shape for product tables (top list + category drill). */
    private function productRow(array $p, ?float $prevRev): array
    {
        $qty = $p['kg'] > 0
            ? round($p['kg'], 1) . ' kg' . ($p['pcs'] > 0 ? ' + ' . round($p['pcs']) . ' pc' : '')
            : round($p['pcs']) . ' pc';
        $avg = $p['kg'] > 0 ? round($p['rev'] / $p['kg']) : ($p['pcs'] > 0 ? round($p['rev'] / $p['pcs']) : 0);
        return [
            'pid'      => $p['pid'],
            'name'     => $p['name'],
            'type'     => $p['type'],
            'cat'      => $p['cat'],
            'revenue'  => round($p['rev']),
            'qty'      => $qty,
            'orders'   => $p['ords'],
            'avg'      => $avg,
            'avg_unit' => $p['kg'] > 0 ? 'kg' : 'pc',
            'prev'     => $prevRev === null ? null : round($prevRev),
        ];
    }

    /**
     * First-time products (first-ever delivered sale falls in the window) and
     * dead listings (active products with no sale in 60+ days). By ORDER date —
     * within days of the delivery date and far cheaper than a history scan.
     * Qurbani-tagged products excluded (seasonal by nature, not "dead").
     */
    private function productLifecycles(Carbon $start, Carbon $end): array
    {
        $qids = $this->qurbaniOrderIds();
        $base = fn () => DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
            })
            ->when(!empty($qids), fn ($q) => $q->whereNotIn('o.id', $qids))
            ->whereRaw("LOWER(COALESCE(p.attribute_1,'')) <> 'qurbani'");

        $freshProducts = $base()
            ->groupBy('li.product_id')
            ->havingRaw('MIN(o.order_date) BETWEEN ? AND ?', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw('MAX(p.title) pname, MIN(o.order_date) first_sold, COALESCE(SUM(li.line_total),0) rev')
            ->orderByDesc('rev')->limit(8)->get()
            ->map(fn ($r) => [
                'name'  => html_entity_decode((string) $r->pname, ENT_QUOTES),
                'first' => Carbon::parse($r->first_sold)->format('M d'),
                'rev'   => round((float) $r->rev),
            ])->all();

        $deadRows = $base()
            ->where('p.status', 'active')
            ->groupBy('li.product_id')
            ->havingRaw('MAX(o.order_date) < ?', [Carbon::now()->subDays(60)->toDateTimeString()])
            ->selectRaw('MAX(p.title) pname, MAX(o.order_date) last_sold, COALESCE(SUM(li.line_total),0) life_rev')
            ->orderByDesc('life_rev')->get();

        $dead = [
            'count' => $deadRows->count(),
            'rows'  => $deadRows->take(15)->map(fn ($r) => [
                'name' => html_entity_decode((string) $r->pname, ENT_QUOTES),
                'last' => Carbon::parse($r->last_sold)->format('M d, y'),
                'rev'  => round((float) $r->life_rev),
            ])->values()->all(),
        ];
        return [$freshProducts, $dead];
    }

    /** Category → the products inside it, for the drill panel. */
    public function productsCategoryList(string $unit, int $year, int $month, string $category): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) return [];
        try {
            [$start, $end] = $this->period($unit, $year, $month);
            [$pStart, $pEnd] = $this->previousPeriod($unit, $year, $month);
            $cur  = $this->productWindowAggregates($unit, $start, $end);
            $prev = $this->productWindowAggregates($unit, $pStart, $pEnd);
            $rows = array_values(array_filter($cur, fn ($p) => $p['cat'] === $category));
            usort($rows, fn ($a, $b) => $b['rev'] <=> $a['rev']);
            return array_map(fn ($p) => $this->productRow($p, $prev[$p['pid']]['rev'] ?? null), $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** One product → its days within the window (drill level 2). */
    public function productDaily(string $unit, int $year, int $month, int $productId): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB || $productId === 0) return [];
        try {
            [$start, $end] = $this->period($unit, $year, $month);
            $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
            $pieceCase = self::pieceCaseSql($kh);
            $q = $this->deliveredLines($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE)
                ->where('li.product_id', $productId);
            return $q->groupBy(DB::raw('DATE(d.delivered_at)'))
                ->selectRaw("DATE(d.delivered_at) day,
                    COALESCE(SUM(li.line_total),0) rev,
                    COALESCE(SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END),0) kg,
                    COALESCE(SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END),0) pcs,
                    COUNT(DISTINCT o.id) ords")
                ->orderByDesc('day')->get()
                ->map(fn ($r) => [
                    'day'    => Carbon::parse($r->day)->format('D · M d'),
                    'qty'    => ((float) $r->kg > 0 ? round((float) $r->kg, 1) . ' kg' : round((float) $r->pcs) . ' pc'),
                    'orders' => (int) $r->ords,
                    'rev'    => round((float) $r->rev),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * SQL CASE condition: TRUE when a line item is sold per PIECE rather than
     * by weight — Khaas packs, or the item name says per piece / pcs / pieces /
     * dozen. Everything else's quantity is genuine kg. (Name-based heuristic —
     * a proper unit flag on products would be cleaner; owner informed.)
     */
    private static function pieceCaseSql(int $khaasId): string
    {
        return "(p.business_unit_id = $khaasId"
            . " OR LOWER(li.name) LIKE '%per piece%'"
            . " OR LOWER(li.name) LIKE '%pcs%'"
            . " OR LOWER(li.name) LIKE '%pieces%'"
            . " OR LOWER(li.name) LIKE '%dozen%')";
    }

    /**
     * All delivered-order + ledger metrics for a NON-Qurbani window, computed in
     * a small fixed number of queries and cached by window. Returns overall +
     * Khaas + NF slices so every unit derives from one computation.
     */
    private function rawWindow(Carbon $start, Carbon $end, ?Carbon $ledgerEnd = null): array
    {
        // Revenue/order metrics use $end (to-date); the cost side uses $lEnd —
        // the full calendar month when the caller widens it, else the same $end.
        $lEnd = $ledgerEnd ?? $end;
        // ⚠ v2 (Sep-2026): the payload gained bal_used / tips_out and `rev` changed
        // meaning. Without the bump, a cached v1 array would be read by new code
        // expecting the new keys — and a closed month caches for 24h.
        $key = 'hq_raw_v2_' . $start->getTimestamp() . '_' . $end->getTimestamp() . '_' . $lEnd->getTimestamp();
        // Closed windows (fully in the past) cache for 24h; the open one 5 min.
        $ttl = $end->lt(Carbon::now()->startOfDay()) ? 86400 : 300;
        return Cache::remember($key, $ttl, function () use ($start, $end, $lEnd) {
            $qmode = QurbaniFinanceFilter::MODE_EXCLUDE;
            $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
            // Customers whose FIRST regular delivery is in this window = the new
            // customers (delivery clock — owner ruling). Resolved once here, then
            // used as an id set so the line-level query can split them by unit.
            $newIds = $this->newCustomerIds($start, $end);
            // Ints only, and '0' matches nothing — safe to interpolate.
            $newIn = empty($newIds) ? '0' : implode(',', $newIds);

            // Q1 — order-level totals.
            // Sep-2026 — `rev` is PROFIT revenue: account balance the customer
            // spent is added back, tips from the cutoff on are taken out (they
            // belong to the Tips Fund, not to us). ONE definition, shared with
            // the Reports tab — see ProfitRevenueSql.
            // ⚠ `rev_kh` below stays line-level and is deliberately untouched:
            // tips and order-level discounts are not line items, so both
            // adjustments land in NF (= rev_all − rev_kh), which is the owner's
            // existing rule for delivery charges and order discounts.
            // ⚠⚠ `d.delivered_at` here is a MAX (see deliveredDatesSub) — right for
            // deciding which month an order lands in, WRONG for the tip cutoff.
            // The Tips Fund collects on an order's FIRST delivery, so the tip test
            // joins that separately; otherwise an order delivered twice across the
            // cutoff would lose its tip from revenue without the fund holding it.
            $firstDel = \App\Services\FIN\ProfitRevenueSql::DEL_ALIAS . '.first_delivered_at';
            $revExpr  = \App\Services\FIN\ProfitRevenueSql::revenue('o', $firstDel);
            $balExpr  = \App\Services\FIN\ProfitRevenueSql::balance();
            $tipsExpr = \App\Services\FIN\ProfitRevenueSql::tipExcluded('o', $firstDel);
            $oq = $this->deliveredBase($start, $end, $qmode);
            \App\Services\FIN\ProfitRevenueSql::join($oq, 'o');
            \App\Services\FIN\ProfitRevenueSql::joinDelivered($oq, 'o');
            $o = $oq
                ->selectRaw(
                    "COALESCE(SUM($revExpr),0) rev,"
                    . " COALESCE(SUM($balExpr),0) bal_used,"
                    . " COALESCE(SUM($tipsExpr),0) tips_out,"
                    . ' COUNT(DISTINCT o.id) ord, COUNT(DISTINCT o.customer_id) cust'
                )
                ->first();

            // Q2 — line-level split (Khaas vs NF) + new-customer flags. $kh and the
            // new-customer ids are ints, so direct interpolation is injection-safe
            // and avoids binding-order issues with the filter.
            // Weight vs pieces: a line counts as PIECES when its product is a
            // Khaas pack or its name says per piece / pcs / pieces / dozen;
            // otherwise quantity is genuine kg (verified against June data).
            $pieceCase = self::pieceCaseSql($kh);
            $l = $this->deliveredLines($start, $end, $qmode)
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END),0) kg_all,
                     COALESCE(SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END),0) pcs_all,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh AND NOT ($pieceCase) THEN li.quantity ELSE 0 END),0) kg_kh,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh AND $pieceCase THEN li.quantity ELSE 0 END),0) pcs_kh,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh THEN li.line_total ELSE 0 END),0) rev_kh,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh THEN o.id END) ord_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) THEN o.id END) ord_nf,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh THEN o.customer_id END) cust_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) THEN o.customer_id END) cust_nf,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh AND o.customer_id IN ($newIn) THEN o.customer_id END) new_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) AND o.customer_id IN ($newIn) THEN o.customer_id END) new_nf"
                )->first();

            // Vendor purchases + expenses, grouped by BU, Qurbani excluded.
            // $lEnd (not $end) so the cost side spans the full calendar month
            // when the caller asked for it (HQ monthly view).
            [$vendorAll, $vendorKh] = $this->ledgerByUnit(LedgerModel::TYPE_VENDOR_PURCHASE, $start, $lEnd, $kh);
            [$expenseAll, $expenseKh] = $this->ledgerByUnit(LedgerModel::TYPE_EXPENSE, $start, $lEnd, $kh);
            // Salaries actually paid in the window (payroll payments + legacy slips),
            // split by the business unit tagged on each employee. A real cost line —
            // NOT part of vendor/expense, shown separately on the P&L.
            [$salaryAll, $salaryKh] = $this->salariesByUnit($start, $lEnd, $kh);

            return [
                'rev_all'   => (float) $o->rev,
                // The two adjustments already inside rev_all, so the revenue
                // tile can show its working instead of just a changed number.
                'bal_used'  => (float) $o->bal_used,
                'tips_out'  => (float) $o->tips_out,
                'ord_all'   => (int) $o->ord,
                'cust_all'  => (int) $o->cust,
                'kg_all'    => (float) $l->kg_all,
                'pcs_all'   => (float) $l->pcs_all,
                'kg_kh'     => (float) $l->kg_kh,
                'pcs_kh'    => (float) $l->pcs_kh,
                'rev_kh'    => (float) $l->rev_kh,
                'ord_kh'    => (int) $l->ord_kh,
                'ord_nf'    => (int) $l->ord_nf,
                'cust_kh'   => (int) $l->cust_kh,
                'cust_nf'   => (int) $l->cust_nf,
                // new_all is the id count itself (the distinct truth); the unit
                // slices below come from the line-level query.
                'new_all'   => count($newIds),
                'new_kh'    => (int) $l->new_kh,
                'new_nf'    => (int) $l->new_nf,
                'vendor_all' => $vendorAll,
                'vendor_kh'  => $vendorKh,
                'vendor_nf'  => $vendorAll - $vendorKh,
                'expense_all' => $expenseAll,
                'expense_kh'  => $expenseKh,
                'expense_nf'  => $expenseAll - $expenseKh,
                'salary_all'  => $salaryAll,
                'salary_kh'   => $salaryKh,
                'salary_nf'   => $salaryAll - $salaryKh,
            ];
        });
    }

    /** Sum a ledger transaction type over a window, grouped into [all, khaas]. Qurbani excluded. */
    private function ledgerByUnit(string $type, Carbon $start, Carbon $end, int $khaasId): array
    {
        $q = LedgerModel::query()
            ->where('transaction_type', $type)
            ->whereIn('approval_status', self::POSTED_STATUSES)
            ->whereBetween('transaction_date', [$start, $end]);
        QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);

        $rows = $q->groupBy('business_unit_id')
            ->selectRaw('business_unit_id as bu, SUM(amount) as amt')->get();

        $all = 0.0; $kh = 0.0;
        foreach ($rows as $r) {
            $all += (float) $r->amt;
            if ((int) $r->bu === $khaasId) $kh = (float) $r->amt;
        }
        return [$all, $kh];
    }

    /**
     * Lightweight revenue + net-profit for the trend line. Avoids the heavy
     * line-level distinct-count query; only revenue (+ Khaas slice when needed)
     * and the two ledger sums.
     */
    private function trendPoint(string $unit, Carbon $start, Carbon $end): array
    {
        if ($unit === self::UNIT_QB) {
            $c = $this->qurbaniClosing($start, $end);
            return ['revenue' => $c['revenue'], 'net_profit' => $c['net_profit']];
        }
        $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
        // Sep-2026 — profit revenue, same rule as the cards this trend sits under.
        $trendQ = $this->deliveredBase($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE);
        \App\Services\FIN\ProfitRevenueSql::join($trendQ, 'o');
        \App\Services\FIN\ProfitRevenueSql::joinDelivered($trendQ, 'o');
        $trendExpr = \App\Services\FIN\ProfitRevenueSql::revenue(
            'o', \App\Services\FIN\ProfitRevenueSql::DEL_ALIAS . '.first_delivered_at'
        );
        $revAll = (float) ($trendQ->selectRaw("COALESCE(SUM($trendExpr),0) rev")->first()->rev ?? 0);
        [$vendAll, $vendKh] = $this->ledgerByUnit(LedgerModel::TYPE_VENDOR_PURCHASE, $start, $end, $kh);
        [$expAll, $expKh]   = $this->ledgerByUnit(LedgerModel::TYPE_EXPENSE, $start, $end, $kh);
        [$salAll, $salKh]   = $this->salariesByUnit($start, $end, $kh);

        if ($unit === self::UNIT_ALL) {
            $rev = $revAll; $vend = $vendAll; $exp = $expAll; $sal = $salAll;
        } else {
            $revKh = (float) $this->deliveredLines($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE)
                ->where('p.business_unit_id', $kh)->sum('li.line_total');
            if ($unit === self::UNIT_KH) {
                $rev = $revKh; $vend = $vendKh; $exp = $expKh; $sal = $salKh;
            } else { // NF
                $rev = $revAll - $revKh; $vend = $vendAll - $vendKh; $exp = $expAll - $expKh; $sal = $salAll - $salKh;
            }
        }
        return ['revenue' => round($rev, 0), 'net_profit' => round($rev - $vend - $exp - $sal, 0)];
    }

    /**
     * Salaries actually PAID in a window, split into [all, khaas], from the same
     * cash-basis sources the Expenses page uses: the Payroll screen payments
     * (t_hr_payroll_payment, BU-tagged) plus any legacy salary slips (NF-only).
     * Never Qurbani (salaries are not a Qurbani cost). Guarded so a missing BU
     * column / table degrades to NF rather than erroring.
     */
    private function salariesByUnit(Carbon $start, Carbon $end, int $khaasId): array
    {
        // ONE engine (Sep-2026). HQ, the Reports page and the Expenses page all read salary
        // cost through SalaryCostService, so the three can never disagree about a month's
        // wage bill. It is ACCRUAL: keyed to the month WORKED (`pay_month`), not the day Pay
        // was pressed — paying August in September books August. It also counts advances that
        // have been paid out but not yet recovered, so cash that has left the building is
        // never missing from the P&L. See that class for why neither can double-count.
        $c = (new \App\Services\HR\SalaryCostService())->costForWindow($start, $end, $khaasId);
        return [$c['all'], $c['kh']];
    }

    /**
     * Qurbani closing — seasonal + PRE-BOOKED. Unlike the daily business,
     * Qurbani orders are sold weeks ahead and delivered on the Eid days, with
     * animal costs bought upfront. So the season is measured on a BOOKED basis
     * (all non-cancelled QUR{yy} orders) matched against the season's costs —
     * that is the true season P&L. "Delivered so far" is carried as secondary
     * fulfilment context. (Measuring delivered revenue against all-season
     * expenses understated profit; this fixes both that and the qty.)
     * Season identity = the QUR{yy} order-number prefix (yy from the window).
     */
    private function qurbaniClosing(Carbon $start, Carbon $end): array
    {
        // Season identity = QUR{yy} numbers OR (2025) product-backfilled orders.
        $ids = $this->qurbaniSeasonOrderIds((int) $start->format('Y')) ?: [0];
        $s = $start->toDateTimeString();
        $e = $end->toDateTimeString();

        // BOOKED (non-cancelled) — the season's business.
        $booked = DB::table('t_crm_prod_order as o')
            ->whereIn('o.id', $ids)
            ->where('o.order_status', '<>', 'cancelled')
            ->selectRaw('COALESCE(SUM(o.total_price),0) rev, COUNT(DISTINCT o.id) ord, COUNT(DISTINCT o.customer_id) cust')
            ->first();
        $bookedQty = (float) DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->whereIn('o.id', $ids)
            ->where('o.order_status', '<>', 'cancelled')
            ->sum('li.quantity');
        $newC = (int) DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.id', $ids)
            ->where('o.order_status', '<>', 'cancelled')
            ->whereRaw("c.first_order_date BETWEEN ? AND ?", [$s, $e])
            ->distinct()->count('o.customer_id');

        // DELIVERED so far — fulfilment context.
        $deliv = DB::table('t_crm_prod_order as o')
            ->whereIn('o.id', $ids)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->selectRaw('COALESCE(SUM(o.total_price),0) rev, COUNT(DISTINCT o.id) ord')
            ->first();
        $delivQty = (float) DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->whereIn('o.id', $ids)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->sum('li.quantity');

        $expense = $this->expenses(self::UNIT_QB, $start, $end);
        $revenue = (float) $booked->rev;
        return [
            'revenue'          => round($revenue, 0),
            'orders'           => (int) $booked->ord,
            'kg'               => 0,
            'pieces'           => round($bookedQty, 0),
            'customers'        => (int) $booked->cust,
            'new_customers'    => $newC,
            'vendor_purchases' => 0,
            'gross_profit'     => round($revenue, 0),
            'expenses'         => round($expense, 0),
            'net_profit'       => round($revenue - $expense, 0),
            // Qurbani assets are tracked under NF / Frozen, not here. Same for Storage
            // stock — packaging is bought against NF, never against a Qurbani season.
            'asset_purchases'       => 0,
            'asset_purchases_count' => 0,
            'supplies_bought'       => 0,
            'supplies_bought_count' => 0,
            // secondary — delivered so far this season
            'delivered_revenue' => round((float) $deliv->rev, 0),
            'delivered_orders'  => (int) $deliv->ord,
            'delivered_pieces'  => round($delivQty, 0),
        ];
    }

    // =====================================================================
    //  QUERY HELPERS  (each returns a FRESH builder — no shared state)
    // =====================================================================

    /**
     * Delivered, non-Shopify orders whose delivery timestamp falls in the
     * window. Delivery time = MAX(changed_at) of the 'delivered' status-history
     * rows (mirrors OrderModel::getDeliveryDateAttribute). Qurbani included or
     * excluded per $qmode.
     */
    private function deliveredBase(Carbon $start, Carbon $end, string $qmode)
    {
        // Bounding the delivered-date subquery to the window keeps the grouped
        // scan to that window's rows only — the key to fast per-window queries.
        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub($start, $end), 'd', 'd.order_id', '=', 'o.id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            });
        $this->applyQurbani($q, $qmode);
        return $q;
    }

    /**
     * Include / exclude Qurbani orders via a cached id set instead of a
     * correlated EXISTS. The canonical QurbaniFinanceFilter runs ONCE (cached);
     * every window then filters with a fast whereIn/whereNotIn. This is what
     * makes the seasonal (year-wide) Qurbani view usable.
     */
    private function applyQurbani($q, string $qmode, string $col = 'o.id'): void
    {
        $ids = $this->qurbaniOrderIds();
        if ($qmode === QurbaniFinanceFilter::MODE_INCLUDE) {
            empty($ids) ? $q->whereRaw('1=0') : $q->whereIn($col, $ids);
        } else {
            if (!empty($ids)) $q->whereNotIn($col, $ids);
        }
    }

    /** All Qurbani order ids (canonical definition), resolved once and cached. */
    private function qurbaniOrderIds(): array
    {
        return Cache::remember('hq_qurbani_order_ids', 300, function () {
            $q = DB::table('t_crm_prod_order as o');
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_INCLUDE);
            return $q->pluck('o.id')->map(fn ($v) => (int) $v)->all();
        });
    }

    /**
     * Order ids of ONE Qurbani season (owner ruling Jul-2026): the QUR{yy}-numbered
     * orders of that year PLUS — for seasons that predate the QUR numbering — any
     * canonical-filter Qurbani order placed in that calendar year without a QUR
     * number. The 2025 season exists only via the product/name backfill (bare
     * numeric order numbers), so the prefix alone would miss it entirely; with
     * this fallback the year selector serves 2025 and QUR26's "returning from
     * last season" compares against real 2025 buyers.
     */
    private function qurbaniSeasonOrderIds(int $year): array
    {
        return Cache::remember('hq_qb_season_' . $year, 3600, function () use ($year) {
            $prefix = 'QUR' . substr((string) $year, -2) . '%';
            $ids = DB::table('t_crm_prod_order')
                ->where('order_number', 'like', $prefix)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $canon = $this->qurbaniOrderIds();
            if (!empty($canon)) {
                $extra = DB::table('t_crm_prod_order')
                    ->whereIn('id', $canon)
                    ->whereYear('order_date', $year)
                    ->where('order_number', 'not like', 'QUR%')
                    ->pluck('id')->map(fn ($v) => (int) $v)->all();
                $ids = array_values(array_unique(array_merge($ids, $extra)));
            }
            return $ids;
        });
    }

    /** deliveredBase joined to its line items + products (for kg / unit split). */
    private function deliveredLines(Carbon $start, Carbon $end, string $qmode)
    {
        return $this->deliveredBase($start, $end, $qmode)
            ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id');
    }

    /**
     * Per-order delivered timestamp (latest 'delivered' status change). When a
     * window is given, the scan is bounded to delivered events in that window —
     * far cheaper than grouping the whole history table.
     */
    private function deliveredDatesSub(?Carbon $start = null, ?Carbon $end = null)
    {
        $q = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MAX(changed_at) as delivered_at'))
            ->where('status_code', 'delivered');
        if ($start && $end) {
            $q->whereBetween('changed_at', [$start, $end]);
        }
        return $q->groupBy('order_id');
    }

    /**
     * Expenses for the unit + window. For Qurbani we INCLUDE only qurbani-category
     * rows; for NF/Khaas we EXCLUDE them so qurbani costs never double-count as NF.
     * (Used by the Qurbani closing + reused conceptually; NF/Khaas closings get
     * their expense totals from rawWindow's grouped query instead.)
     */
    private function expenses(string $unit, Carbon $start, Carbon $end): float
    {
        $q = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('approval_status', self::POSTED_STATUSES)
            ->whereBetween('transaction_date', [$start, $end]);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit);
        }
        return (float) $q->sum('amount');
    }

    /**
     * Adds a business_unit_id filter for NF/Khaas; 'all' = NF+Khaas (no filter).
     * $col MUST be table-qualified when the query joins other tables that also
     * carry business_unit_id (t_req_master, t_fin_accounts, t_fin_vendors) —
     * an unqualified column there is ambiguous and 500s the drill.
     */
    private function scopeLedgerToUnit($q, string $unit, string $col = 't_fin_ledger.business_unit_id'): void
    {
        if ($unit === self::UNIT_NF) {
            $q->where($col, 1);
        } elseif ($unit === self::UNIT_KH) {
            $khaasId = $this->khaasBusinessUnitId();
            $q->where($col, $khaasId ?? -1);
        }
        // UNIT_ALL: no BU filter (qurbani already excluded upstream).
    }

    // =====================================================================
    //  WORKING CAPITAL SUB-QUERIES
    // =====================================================================


    /**
     * Closing-health signals for the working-capital strip. Each is defensive
     * (try/catch → skip) so a missing table never breaks the dashboard.
     *
     * @param float $segregated tracked per-bank balances + unassigned bucket
     * @param float $onlinePool sum of the ONLINE-pool chart accounts
     */
    private function healthChecks(float $segregated, float $onlinePool): array
    {
        $out = [];

        // 1) Delivered REGULAR orders (last 30d) with no invoice ledger entry.
        //    Shop customers are excluded — they settle incrementally and by
        //    design never get a full invoice posting, so they are not anomalies.
        try {
            $missing = $this->missingInvoiceBase()->count();
            $out[] = [
                'ok'    => $missing === 0,
                'label' => $missing === 0
                    ? 'Every delivered order (30d) has an invoice entry'
                    : "{$missing} delivered orders (30d) missing an invoice entry",
                // drill key → the frontend makes this row clickable to the list.
                'drill' => $missing > 0 ? 'missing_invoices' : null,
            ];
        } catch (\Throwable $e) { /* skip */ }

        // 2) Per-bank segregation (tracked + unassigned) vs the ONLINE pool.
        //    Both numbers come from the Bank Balances page's own definitions;
        //    a gap means opening balances / true-ups need a look.
        try {
            $diff = abs($onlinePool - $segregated);
            $out[] = [
                'ok'    => $diff < 1000,
                'label' => $diff < 1000
                    ? 'Per-bank segregation reconciles with the ONLINE pool'
                    : 'Per-bank segregation is Rs ' . number_format($diff) . ' off the ONLINE pool (check openings / true-ups on Bank Balances)',
            ];
        } catch (\Throwable $e) { /* skip */ }

        // 3) Payment proofs unmatched > 7 days.
        try {
            $stale = DB::table('t_fin_payment_signal')
                ->whereIn('status', ['new', 'unmatched'])
                ->where('created_at', '<', Carbon::now()->subDays(7))
                ->count();
            $out[] = [
                'ok'    => $stale === 0,
                'label' => $stale === 0
                    ? 'No unmatched payment proofs older than 7 days'
                    : "{$stale} payment proofs unmatched for over 7 days",
            ];
        } catch (\Throwable $e) { /* skip */ }

        return $out;
    }

    // =====================================================================
    //  PERIOD / UTILITY
    // =====================================================================

    /**
     * Resolve the [start, end, label, isOpen] for a unit + year/month.
     * Qurbani = whole calendar year (season-to-date if the current year).
     */
    // =====================================================================
    // RETURNS (Sep-2026) — delivered orders the customer sent back
    // =====================================================================

    /**
     * Level 0 — the headline card.
     *
     * ⭐⭐ Read from t_crm_order_return, never inferred from the order status.
     * The status only says an order came back; it cannot say whether the money
     * was reversed, refunded or credited, nor whether the meat went to a shelf
     * or the bin. Those are decisions, and the record is where they live.
     *
     * The period is keyed on when the return was TAKEN (decided_at), not the
     * original delivery: that is the month the money actually moved, so the card
     * reconciles with the ledger rows the return created.
     */
    public function returnsSummary(string $unit, int $year, int $month): array
    {
        if (!$this->returnsReady()) {
            return ['ready' => false, 'count' => 0, 'value' => 0.0];
        }

        $unit = $this->normalizeUnit($unit);
        [$start, $end] = $this->period($unit, $year, $month);

        $rows = $this->returnsBase($unit, $start, $end)
            ->get(['r.money_action', 'r.amount', 'r.goods_action', 'r.tip_returned', 'r.tip_amount',
                   'r.completed_at', 'r.completed_short', 'o.total_price']);

        $out = [
            'ready'          => true,
            'count'          => $rows->count(),
            'value'          => round((float) $rows->sum('total_price'), 2),
            'money_back'     => round((float) $rows->sum('amount'), 2),
            'reversed'       => $rows->where('money_action', 'reversed')->count(),
            'refunded'       => $rows->where('money_action', 'refund')->count(),
            'credited'       => $rows->where('money_action', 'credit')->count(),
            'no_money'       => $rows->where('money_action', 'none')->count(),
            'refunded_value' => round((float) $rows->where('money_action', 'refund')->sum('amount'), 2),
            'credited_value' => round((float) $rows->where('money_action', 'credit')->sum('amount'), 2),
            'tips_returned'  => round((float) $rows->where('tip_returned', 1)->sum('tip_amount'), 2),
            'restocked'      => $rows->where('goods_action', 'restock')->count(),
            'wasted'         => $rows->where('goods_action', 'wasted')->count(),
            'awaiting'       => $rows->where('goods_action', 'restock')->whereNull('completed_at')->count(),
            'short'          => $rows->where('completed_short', 1)->count(),
        ];

        return $out;
    }

    /** Level 1 — the returned orders behind the card. */
    public function returnsDetail(string $unit, int $year, int $month): array
    {
        if (!$this->returnsReady()) {
            return [];
        }

        $unit = $this->normalizeUnit($unit);
        [$start, $end] = $this->period($unit, $year, $month);

        return $this->returnsBase($unit, $start, $end)
            ->leftJoin('t_sys_user as du', 'du.id', '=', 'r.decided_by')
            ->orderByDesc('r.decided_at')
            ->limit(500)
            ->get([
                'r.id', 'r.decided_at', 'r.reason', 'r.money_action', 'r.money_state', 'r.amount',
                'r.tip_returned', 'r.tip_amount', 'r.goods_action', 'r.meat_section',
                'r.completed_at', 'r.completed_short', 'r.short_reason',
                'o.id as order_id', 'o.order_number', 'o.total_price', 'o.name as customer',
                'du.fullname as who',
            ])
            ->map(fn ($r) => [
                'return_id'   => (int) $r->id,
                'order_id'    => (int) $r->order_id,
                'order'       => $r->order_number,
                'customer'    => $r->customer ?: '—',
                'date'        => $r->decided_at ? Carbon::parse($r->decided_at)->format('M d') : '—',
                'who'         => $r->who ?: 'System',
                'reason'      => $r->reason ?: '—',
                'value'       => round((float) $r->total_price, 2),
                'money'       => $this->returnMoneyLabel($r->money_action),
                'money_key'   => $r->money_action,
                'amount'      => round((float) $r->amount, 2),
                'tip'         => $r->tip_returned ? round((float) $r->tip_amount, 2) : 0.0,
                'goods'       => $r->goods_action === 'wasted'
                    ? 'Written off'
                    : 'Back to ' . ($r->meat_section ? $r->meat_section : 'stock'),
                'put_back'    => $r->goods_action === 'wasted'
                    ? 'n/a'
                    : ($r->completed_at ? ($r->completed_short ? 'Short' : 'Done') : 'Waiting'),
                'short_reason' => $r->short_reason,
            ])->all();
    }

    /** Level 2 — the items and the ledger rows behind ONE return. */
    public function returnDetail(int $returnId): array
    {
        if (!$this->returnsReady()) {
            return ['items' => [], 'ledger' => []];
        }

        $return = DB::table('t_crm_order_return')->where('id', $returnId)->first();
        if (!$return) {
            return ['items' => [], 'ledger' => []];
        }

        $items = DB::table('t_crm_order_return_item as ri')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'ri.product_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'ri.scanned_by')
            ->where('ri.return_id', $returnId)
            ->orderBy('ri.id')
            ->get(['ri.quantity', 'ri.unit', 'ri.destination', 'ri.barcode', 'ri.scanned_at',
                   'p.title', 'u.fullname as who'])
            ->map(fn ($r) => [
                'item'        => $r->title ?: 'Item',
                'quantity'    => round((float) $r->quantity, 3) . ' ' . $r->unit,
                'destination' => $r->destination,
                'how'         => $r->barcode ? 'scanned' : 'manual',
                'who'         => $r->who ?: '—',
                'at'          => $r->scanned_at ? Carbon::parse($r->scanned_at)->format('M d H:i') : '—',
            ])->all();

        // Every money row this return produced, so the drill can be reconciled
        // against the ledger without leaving the screen.
        $ledger = DB::table('t_fin_ledger')
            ->where('order_id', $return->order_id)
            ->whereIn('transaction_type', [
                LedgerModel::TYPE_ORDER_REFUND,
                LedgerModel::TYPE_INVOICE,
                LedgerModel::TYPE_TIP_COLLECTED,
                'customer_credit_grant',
            ])
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'transaction_date', 'transaction_type', 'description', 'amount', 'approval_status'])
            ->map(fn ($r) => [
                'id'     => (int) $r->id,
                'date'   => Carbon::parse($r->transaction_date)->format('M d'),
                'type'   => LedgerModel::typeLabel($r->transaction_type),
                'note'   => $r->description,
                'amount' => round((float) $r->amount, 2),
                'status' => $r->approval_status,
            ])->all();

        return ['items' => $items, 'ledger' => $ledger];
    }

    /**
     * The shared scope. Qurbani is excluded exactly like everywhere else here.
     * (A Qurbani order WITH recorded payments is refused a return; one with none
     * yet can be returned with money_action = none, so the filter still matters.)
     */
    private function returnsBase(string $unit, Carbon $start, Carbon $end)
    {
        $q = DB::table('t_crm_order_return as r')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'r.order_id')
            ->whereBetween('r.decided_at', [$start, $end]);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_EXCLUDE);
        }

        return $q;
    }

    private function returnMoneyLabel(?string $action): string
    {
        return match ($action) {
            'reversed' => 'Invoice reversed',
            'refund'   => 'Refunded',
            'credit'   => 'To account balance',
            default    => 'No money',
        };
    }

    /**
     * Dormant until the returns SQL has been run. Manual deploys put the PHP up
     * first, and an HQ card that 500s the whole dashboard would be a poor trade
     * for a feature nobody is using yet.
     */
    private function returnsReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = \Schema::hasTable('t_crm_order_return')
                    && \Schema::hasTable('t_crm_order_return_item');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    private function period(string $unit, int $year, int $month): array
    {
        $now = Carbon::now();
        if ($unit === self::UNIT_QB) {
            $start = Carbon::create($year, 1, 1)->startOfDay();
            $isOpen = $year === $now->year;
            $end = $isOpen ? $now->copy() : Carbon::create($year, 12, 31)->endOfDay();
            return [$start, $end, "Qurbani {$year} season", $isOpen];
        }
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $start->copy()->endOfMonth();
        $isOpen = ($year === $now->year && $month === $now->month);
        $end = $isOpen && $now->lt($monthEnd) ? $now->copy() : $monthEnd->endOfDay();
        return [$start, $end, $start->format('F Y'), $isOpen];
    }

    private function previousPeriod(string $unit, int $year, int $month): array
    {
        if ($unit === self::UNIT_QB) {
            $s = Carbon::create($year - 1, 1, 1)->startOfDay();
            $e = Carbon::create($year - 1, 12, 31)->endOfDay();
            return [$s, $e];
        }
        $ref = Carbon::create($year, $month, 1)->subMonth();
        $prevStart = $ref->copy()->startOfMonth();
        $prevEnd   = $ref->copy()->endOfMonth()->endOfDay();

        // When the requested period is the CURRENT (open) month, the current
        // window is only the elapsed part of the month (period() ends it at
        // "now"). Mirror that same span onto the previous month so the deltas
        // compare like-for-like — the first N days of this month vs the first N
        // days of last month — instead of a partial month against a full one
        // (which made every open month read as a big drop). The guard only ever
        // SHRINKS the previous window, never extends it past the real month end.
        $now = Carbon::now();
        if ($year === (int) $now->year && $month === (int) $now->month) {
            $mirrorEnd = $prevStart->copy()->addDays((int) $now->day - 1)->endOfDay();
            if ($mirrorEnd->lt($prevEnd)) {
                $prevEnd = $mirrorEnd;
            }
        }
        return [$prevStart, $prevEnd];
    }

    /**
     * Cost-side window end for the monthly closing: the FULL calendar month, so
     * the HQ P&L counts the whole month's vendor / expense / asset ledger rows
     * exactly like the Reports tab (for the open month this reaches past "now",
     * picking up post-dated entries; for a closed month it is a no-op since the
     * window already ends at month-end). Qurbani is seasonal — left untouched.
     */
    private function ledgerEndFor(string $unit, Carbon $end): ?Carbon
    {
        return $unit === self::UNIT_QB ? null : $end->copy()->endOfMonth()->endOfDay();
    }

    /** Calendar days in the window minus active public holidays. */
    private function workingDays(Carbon $start, Carbon $end): int
    {
        $days = $start->diffInDays($end) + 1;
        try {
            $holidays = DB::table('t_ops_public_holidays')
                ->where('is_active', 1)
                ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
                ->count();
            $days -= $holidays;
        } catch (\Throwable $e) { /* table optional */ }
        return max($days, 1);
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return in_array($unit, [self::UNIT_ALL, self::UNIT_NF, self::UNIT_KH, self::UNIT_QB], true)
            ? $unit : self::UNIT_ALL;
    }

    /** Khaas business-unit id, resolved case-insensitively and cached. */
    private function khaasBusinessUnitId(): ?int
    {
        if ($this->khaasIdCache !== null) {
            return $this->khaasIdCache ?: null;
        }
        try {
            $id = DB::table('t_fin_business_units')
                ->whereRaw("LOWER(code) = 'khaas'")
                ->orWhereRaw("LOWER(name) = 'khaas'")
                ->value('id');
            $this->khaasIdCache = (int) ($id ?: 0);
        } catch (\Throwable $e) {
            $this->khaasIdCache = 0;
        }
        return $this->khaasIdCache ?: null;
    }
}
