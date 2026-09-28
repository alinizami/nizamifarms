<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\FIN\BusinessUnitModel;
use App\Models\FIN\VendorModel;
use App\Models\FIN\LedgerModel;
use App\Models\CRM\ProductModel;
use App\Models\CRM\ProductVariantModel;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\CRM\WarehouseInventoryLogModel;
use App\Models\CRM\WarehouseTransferModel;
use App\Models\CRM\WarehouseTransferRequestModel;
use App\Models\Request\RequestModel;

class KhaasController extends Controller
{
    /**
     * Get the Khaas business unit (id=2, code=KHAAS)
     */
    private function getKhaasBU()
    {
        return BusinessUnitModel::where('code', 'KHAAS')
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Check if current user has Khaas access
     * Uses mobile permission system: checks for 'access_khaas_mode' permission
     * This is the same permission the mobile app checks via /rider/permissions
     */
    private function hasKhaasAccess()
    {
        $user = auth()->user();
        if (!$user) return false;

        // Load roles with mobile permissions if not already loaded
        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        // Check using the mobile permission system (same as mobile app)
        return $user->hasMobilePermission('access_khaas_mode');
    }

    /**
     * Khaas Dashboard - overview hub
     */
    public function dashboard()
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Get summary stats
        $vendorCount = VendorModel::where('business_unit_id', $khaasBU->id)->where('is_active', 1)->count();
        $productCount = ProductModel::where('business_unit_id', $khaasBU->id)->where('is_active', 1)->count();
        
        $totalVendorBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)
            ->with('account')
            ->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        // Warehouse inventory stats
        $warehouseItems = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->count();
        $totalWarehouseQty = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->sum('quantity');
        $lowStockItems = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'min_stock_level')
            ->where('min_stock_level', '>', 0)
            ->count();

        // Pending transfers
        $pendingTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->count();

        // Expenses this month
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $monthlyExpenses = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$monthStart])
            ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$monthEnd])
            ->sum('amount');

        // Recent warehouse activity
        $recentActivity = WarehouseInventoryLogModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Recent transfers
        $recentTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'requester:id,fullname'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // ⭐ SALES REPORT: Get Khaas product sales from order line items
        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');
        
        // This month's sales
        $monthlySalesData = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            })
            ->selectRaw('SUM(line_total) as total_revenue, SUM(quantity) as total_units, COUNT(DISTINCT order_id) as total_orders')
            ->first();
        
        $monthlySalesRevenue = $monthlySalesData->total_revenue ?? 0;
        $monthlySalesUnits = $monthlySalesData->total_units ?? 0;
        $monthlySalesOrders = $monthlySalesData->total_orders ?? 0;

        // Recent Khaas sales (last 10 orders with Khaas products)
        $recentSales = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) {
                $q->whereNotIn('order_status', ['cancelled']);
            })
            ->with(['order:id,order_number,order_date,order_status,total_price,customer_id', 'order.customer:id,first_name,last_name,phone_original'])
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->groupBy('order_id')
            ->take(8);

        return view('khaas.dashboard', compact(
            'khaasBU', 'vendorCount', 'productCount', 'totalVendorBalance',
            'warehouseItems', 'totalWarehouseQty', 'lowStockItems',
            'pendingTransfers', 'monthlyExpenses', 'recentActivity', 'recentTransfers',
            'monthlySalesRevenue', 'monthlySalesUnits', 'monthlySalesOrders', 'recentSales'
        ));
    }

    /**
     * 🛒 Outstanding order demand per product — now ONE service shared with the phone
     * (App\Services\Khaas\OpenOrderDemandService, Sep-26). Moved there unchanged, plus the
     * accepted / pending (later day) split. [product_id => accepted, pending, total, shopify]
     * — `total` is the open-order headline; the Shopify approval queue is separate.
     */
    private function pendingOrderDemandByProduct(int $khaasBuId): array
    {
        return app(\App\Services\Khaas\OpenOrderDemandService::class)->byProduct($khaasBuId, true);
    }

    /**
     * GET /khaas/products/{productId}/pending-orders
     *
     * The per-order breakdown behind the card's "Pending orders" number: exactly the
     * rows that were counted, so the popup total always reconciles with the card.
     * Also served to mobile if it ever wants it — same access rule as the store log.
     */
    public function pendingOrdersBreakdown(Request $request, $productId)
    {
        $user = auth()->user();
        $canAccess = $this->hasKhaasAccess()
            || ($user && $user->hasMobilePermission('view_khaas_store_inventory'))
            || ($user && $user->hasMobilePermission('access_store_mode'));
        if (!$canAccess) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $product = ProductModel::find($productId);
        if (!$product || (int) $product->business_unit_id !== (int) $khaasBU->id) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        try {
            // One row per ORDER — exactly the rows the card counted (same service).
            $rows = app(\App\Services\Khaas\OpenOrderDemandService::class)
                ->ordersForProduct((int) $khaasBU->id, (int) $productId, true);
            $open    = collect($rows['open']);
            $shopify = collect($rows['shopify']);

            return response()->json([
                'success' => true,
                'product_name' => $product->title,
                'shopify' => $shopify,
                'open' => $open,
                'shopify_total' => $shopify->sum('qty'),
                'open_total' => $open->sum('qty'),
                'accepted_total' => $open->where('bucket', 'accepted')->sum('qty'),
                'pending_total' => $open->where('bucket', 'pending')->sum('qty'),
                // ⭐ Sep-26: the headline is OPEN orders (accepted + later day), the same
                //   number the phone shows. The Shopify queue is reported beside it.
                'total' => $open->sum('qty'),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Pending orders breakdown failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load orders'], 500);
        }
    }

    /**
     * Khaas Products - with store vs warehouse inventory comparison
     */
    public function products(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Build product query filtered by Khaas BU
        $query = ProductModel::where('business_unit_id', $khaasBU->id)
            ->with(['variants']);

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('handle', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Category filter (attribute_1)
        if ($request->has('category') && $request->category) {
            $query->where('attribute_1', $request->category);
        }

        $products = $query->orderBy('title')->paginate(25);

        // Get warehouse inventory for all Khaas products
        $warehouseInventory = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->get()
            ->groupBy('product_id')
            ->map(function($items) {
                return [
                    'warehouse_qty' => $items->sum('quantity'),
                    'unit' => $items->first()->unit ?? 'pcs',
                    'warehouse_location' => $items->first()->warehouse_location,
                    'min_stock_level' => $items->first()->min_stock_level ?? 0,
                ];
            });

        // Get pending transfers (qty grouped by product for display on cards)
        $pendingTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->where('to_location', 'store')
            ->get()
            ->groupBy('product_id')
            ->map(fn($items) => $items->sum('quantity'));

        // Get pending transfer records (for approval queue on same page)
        $pendingTransferRecords = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get categories for filter dropdown
        $categories = ProductModel::where('business_unit_id', $khaasBU->id)
            ->distinct()
            ->pluck('attribute_1')
            ->filter()
            ->sort()
            ->values();

        // "Counted by" picker options for the Pending Transfer Approvals banner.
        $countedByUsers = $this->activeUsersForCountedBy();

        // ⭐ Outstanding order demand per product, so the manager can see how much is
        // already spoken for before deciding what to request.
        $orderDemand = $this->pendingOrderDemandByProduct($khaasBU->id);

        // Open transfer requests: keyed by product for the card badge, plus the full
        // records for the banner. Both are empty collections until the SQL is run.
        $pendingRequestsByProduct = WarehouseTransferRequestModel::pendingByProduct($khaasBU->id);

        $pendingRequestRecords = collect();
        $declinedRequestRecords = collect();
        if (WarehouseTransferRequestModel::supported()) {
            $pendingRequestRecords = WarehouseTransferRequestModel::where('business_unit_id', $khaasBU->id)
                ->where('status', WarehouseTransferRequestModel::STATUS_PENDING)
                ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname'])
                ->orderBy('created_at', 'asc')
                ->get();

            // Recently declined — web gets no push, so without this a manager would
            // never learn his request was refused; it would just silently vanish.
            $declinedRequestRecords = WarehouseTransferRequestModel::where('business_unit_id', $khaasBU->id)
                ->where('status', WarehouseTransferRequestModel::STATUS_DECLINED)
                ->where('declined_at', '>=', now()->subDays(7))
                ->with(['product:id,title', 'requester:id,fullname', 'decliner:id,fullname'])
                ->orderBy('declined_at', 'desc')
                ->limit(10)
                ->get();
        }

        $canFulfilRequests = optional(auth()->user())->hasMobilePermission('access_khaas_mode') ?? false;

        // ❄ Sep-2026 — this month's estimated ingredient cost per pack, plus how the
        // packs arrived. One query for the whole page, not one per card. It fails soft:
        // a costing problem must never take the Products page down with it.
        $recipeCosts = [];
        $recipeCoverage = [];
        try {
            $month = now()->format('Y-m');
            $costing = new \App\Services\Khaas\FrozenCostingService();
            foreach ($costing->productMonth((int) $khaasBU->id, $month)['rows'] as $row) {
                $recipeCosts[(int) $row['product_id']] = $row;
            }
            foreach ((new \App\Services\Khaas\RecipeService())->coverage((int) $khaasBU->id) as $row) {
                $recipeCoverage[(int) $row['product_id']] = $row;
            }
        } catch (\Throwable $e) {
            \Log::warning('Khaas products: recipe cost chip failed', ['error' => $e->getMessage()]);
        }

        $canSeeIngredientCost = $this->canSeeIngredientCost();
        $canManageRecipes     = $this->canManageRecipes();

        // 💲 Sep-27 — the recipe at TODAY'S prices (each ingredient's newest bill), for
        // every product that has one, shown even before anything is made this month.
        // Same engine and same stripping as the phone's /warehouse/product-costs.
        $recipePrices = [];
        try {
            $recipePrices = (new \App\Services\Khaas\IngredientPriceService())->forProducts((int) $khaasBU->id);
            if (!$canSeeIngredientCost) {
                $recipePrices = \App\Services\Khaas\IngredientPriceService::stripProductCosts($recipePrices);
            }
        } catch (\Throwable $e) {
            \Log::warning('Khaas products: recipe price chip failed', ['error' => $e->getMessage()]);
        }

        return view('khaas.products', compact(
            'khaasBU', 'products', 'warehouseInventory', 'pendingTransfers', 'pendingTransferRecords', 'categories',
            'countedByUsers', 'orderDemand', 'pendingRequestsByProduct', 'pendingRequestRecords',
            'declinedRequestRecords', 'canFulfilRequests',
            'recipeCosts', 'recipeCoverage', 'canSeeIngredientCost', 'canManageRecipes', 'recipePrices'
        ));
    }

    /**
     * Khaas Operations - combined page for Vendors, Expenses, Transfers (tabs)
     */
    public function operations(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'vendors');

        // === VENDORS DATA ===
        $vendorQuery = VendorModel::where('business_unit_id', $khaasBU->id)->with(['account']);
        $vendorStatus = $request->input('vendor_status', 'active');
        if ($vendorStatus !== 'all') {
            $vendorQuery->where('is_active', $vendorStatus === 'active' ? 1 : 0);
        }
        if ($request->has('vendor_search') && $request->vendor_search) {
            $search = $request->vendor_search;
            $vendorQuery->where(function($q) use ($search) {
                $q->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%");
            });
        }
        $vendors = $vendorQuery->orderBy('vendor_name', 'asc')->paginate(25, ['*'], 'vendors_page');

        $totalVendorBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)->with('account')->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        // === EXPENSES DATA ===
        $hasDateFilter = $request->has('date_from') || $request->has('date_to');
        if (!$hasDateFilter) {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        } else {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
        }
        $expCategory = $request->input('exp_category');
        $settlementStatus = $request->input('settlement_status');

        // ⭐ Approved expenses (includes khaas_expense category)
        $expensesQuery = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category']);

        if ($dateFrom && $dateTo) {
            $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                          ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
        }
        if ($expCategory) {
            $expensesQuery->whereRaw('LOWER(expense_category) = ?', [strtolower($expCategory)]);
        }

        $expenses = $expensesQuery->orderBy('created_at', 'desc')->paginate(25, ['*'], 'expenses_page');

        $allExpensesForKPI = (clone $expensesQuery)->get();
        $totalExpenses = $allExpensesForKPI->sum('amount');

        // ⭐ Pending approval expenses for this BU
        $pendingExpenses = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->where('status', RequestModel::STATUS_PENDING)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category', 'approvals.approver'])
            ->orderBy('created_at', 'asc')
            ->get();
        $pendingExpenseCount = $pendingExpenses->count();
        $pendingExpenseAmount = $pendingExpenses->sum('amount');

        // ⭐ BU-specific payment source accounts for display
        $buPaymentAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
            ->where('business_unit_id', $khaasBU->id)
            ->whereNotIn('account_category', [
                \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH,
                \App\Models\FIN\AccountModel::CATEGORY_VENDOR_PAYABLE,
            ])
            ->orderBy('account_name')
            ->get();

        $expenseCategories = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->where('business_unit_id', $khaasBU->id)
            ->distinct()->pluck('expense_category')->filter()->sort()->values();

        // === TRANSFERS DATA ===
        $transferStatus = $request->input('transfer_status', 'pending');
        $transferQuery = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname', 'approver:id,fullname', 'counter:id,fullname']);
        if ($transferStatus !== 'all') {
            $transferQuery->where('status', $transferStatus);
        }
        $transfers = $transferQuery->orderBy('created_at', 'desc')->paginate(25, ['*'], 'transfers_page');

        $pendingTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'pending')->count();
        $approvedTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'approved')->count();
        $rejectedTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'rejected')->count();

        // "Counted by" picker options (Aug-2026). All active staff, per the
        // owner's ruling — the counter is not always a Frozen/store user.
        $countedByUsers = $this->activeUsersForCountedBy();

        return view('khaas.operations', compact(
            'khaasBU', 'activeTab',
            // Vendors
            'vendors', 'totalVendorBalance', 'vendorStatus',
            // Expenses
            'expenses', 'totalExpenses', 'pendingExpenses', 'pendingExpenseCount', 'pendingExpenseAmount',
            'buPaymentAccounts',
            'dateFrom', 'dateTo', 'expCategory', 'expenseCategories',
            // Transfers
            'transfers', 'transferStatus', 'pendingTransferCount', 'approvedTransferCount', 'rejectedTransferCount',
            'countedByUsers'
        ));
    }

    /**
     * Staff for the transfer "Counted by" picker. Same roster as the Attendance
     * page ("Customize user list"), plus the current user — see
     * User::countedByCandidates() for why. One helper, shared with the mobile
     * endpoint, so the two lists can never drift apart.
     */
    private function activeUsersForCountedBy()
    {
        return \App\Models\User::countedByCandidates(auth()->id());
    }

    /**
     * Khaas Vendors - filtered by Khaas BU
     */
    public function vendors(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $query = VendorModel::where('business_unit_id', $khaasBU->id)
            ->with(['account']);

        // Status filter
        $status = $request->input('status', 'active');
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active' ? 1 : 0);
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%");
            });
        }

        $vendors = $query->orderBy('vendor_name', 'asc')->paginate(25);

        // Total balance
        $totalBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)
            ->with('account')
            ->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        // 📊 Sep-2026: the Month Review's cost type is editable here too, so a
        // vendor can be filed the moment it is created rather than only when
        // someone notices it in the "not classified" bucket.
        $vendorCostTypes = \App\Models\FIN\CostTypeMapModel::mapFor((int) $khaasBU->id)[
            \App\Models\FIN\CostTypeMapModel::KIND_VENDOR
        ] ?? [];
        $canSeeCosts = $this->canSeeMonthCosts();
        $costTypes = \App\Models\FIN\CostTypeMapModel::types();

        return view('khaas.vendors', compact(
            'khaasBU', 'vendors', 'totalBalance', 'status',
            'vendorCostTypes', 'canSeeCosts', 'costTypes'
        ));
    }

    /**
     * Khaas Expenses - filtered by Khaas BU
     */
    public function expenses(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Get filter parameters
        $hasDateFilter = $request->has('date_from') || $request->has('date_to');
        if (!$hasDateFilter) {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        } else {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
        }
        $category = $request->input('category');
        $settlementStatus = $request->input('settlement_status');

        // Build query filtered by Khaas BU
        $expensesQuery = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy']);

        if ($dateFrom && $dateTo) {
            $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                          ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
        }
        if ($category) {
            $expensesQuery->whereRaw('LOWER(expense_category) = ?', [strtolower($category)]);
        }
        if ($settlementStatus) {
            $expensesQuery->where('settlement_status', $settlementStatus);
        }

        $expenses = $expensesQuery->orderBy('created_at', 'desc')->paginate(25);

        // KPIs
        $allExpensesForKPI = (clone $expensesQuery)->get();
        $totalExpenses = $allExpensesForKPI->sum('amount');
        $settledExpenses = $allExpensesForKPI->where('settlement_status', 'settled')->sum('amount');
        $pendingSettlement = $allExpensesForKPI->where('settlement_status', 'pending')->sum('amount');

        // Categories for filter
        $expenseCategories = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->where('business_unit_id', $khaasBU->id)
            ->distinct()
            ->pluck('expense_category')
            ->filter()
            ->sort()
            ->values();

        return view('khaas.expenses', compact(
            'khaasBU', 'expenses', 'totalExpenses', 'settledExpenses', 'pendingSettlement',
            'dateFrom', 'dateTo', 'category', 'settlementStatus', 'expenseCategories'
        ));
    }

    /**
     * Warehouse Transfers - approval queue
     */
    public function transfers(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $status = $request->input('status', 'pending');

        $query = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname', 'approver:id,fullname', 'counter:id,fullname']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $transfers = $query->orderBy('created_at', 'desc')->paginate(25);

        // Stats
        $pendingCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'pending')->count();
        $approvedCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'approved')->count();
        $rejectedCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'rejected')->count();

        $countedByUsers = $this->activeUsersForCountedBy();

        return view('khaas.transfers', compact(
            'khaasBU', 'transfers', 'status', 'pendingCount', 'approvedCount', 'rejectedCount',
            'countedByUsers'
        ));
    }

    /**
     * Approve/reject transfer from web (reuses existing API logic)
     */
    public function approveTransfer(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $transfer = WarehouseTransferModel::findOrFail($id);
        
        if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
            return back()->with('error', 'Transfer is not pending.');
        }

        // ⭐ Aug-2026 audit: who physically COUNTED the stock. Optional, and
        // separate from the approver because the manager approves while somebody
        // else counts. Absent => NULL ("not recorded"), never inferred from the
        // approver. Same rule as the mobile path (WarehouseController).
        $countedBy = WarehouseTransferModel::normaliseCountedBy($request->input('counted_by'));
        if ($countedBy === false) {
            return back()->with('error', 'The selected "Counted by" user is not valid or is no longer active.');
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'status' => WarehouseTransferModel::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ];
            // Gated: the column only exists once BATCH-16 has been run, and web
            // files can be uploaded before the SQL is.
            if ($countedBy !== null && WarehouseTransferModel::supportsCountedBy()) {
                $updateData['counted_by'] = $countedBy;
            }
            $transfer->update($updateData);

            // ⭐ Resolve variant: use transfer's variant_id, or fallback to product's first variant
            $variantId = $transfer->product_variant_id;
            $variant = null;
            if ($variantId) {
                $variant = ProductVariantModel::find($variantId);
            }
            if (!$variant && $transfer->product_id) {
                $variant = ProductVariantModel::where('product_id', $transfer->product_id)->first();
                if ($variant) {
                    $transfer->update(['product_variant_id' => $variant->id]);
                }
            }
            
            // Add to store inventory (variant's inventory_quantity is Shop stock)
            if ($variant) {
                $variant->increment('inventory_quantity', $transfer->quantity);
            }
            
            // Sync product's total_inventory from variant(s)
            $product = ProductModel::find($transfer->product_id);
            if ($product) {
                $newTotal = ProductVariantModel::where('product_id', $product->id)->sum('inventory_quantity');
                $product->update(['total_inventory' => $newTotal]);
            }

            DB::commit();
            return back()->with('success', "Transfer of {$transfer->quantity} units approved. Store inventory updated.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to approve transfer: ' . $e->getMessage());
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TRANSFER REQUESTS (web doors)
    //
    // ⭐ These deliberately hold NO logic. Each one shapes a Request and hands it
    // to WarehouseController — the same delegation KhaasController::createDemand
    // already uses — so the web and the mobile app can never drift apart. The
    // permission checks, the locking, the one-open-request rule and the stock
    // movement all live in exactly one place.
    // ═════════════════════════════════════════════════════════════════════════

    /** Ask the warehouse for stock (or edit the open ask for that product). */
    public function createTransferRequest(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $apiRequest = new Request([
            'product_id' => $request->input('product_id'),
            'product_variant_id' => $request->input('product_variant_id'),
            'business_unit_id' => $khaasBU->id,
            'quantity' => $request->input('quantity'),
            'notes' => $request->input('notes'),
        ]);

        return $this->flashFromApi(
            app(\App\Http\Controllers\CRM\WarehouseController::class)->createTransferRequest($apiRequest),
            'Failed to send request.'
        );
    }

    public function cancelTransferRequest(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        return $this->flashFromApi(
            app(\App\Http\Controllers\CRM\WarehouseController::class)->cancelTransferRequest(new Request(), $id),
            'Failed to cancel request.'
        );
    }

    /**
     * Accept a request from the web.
     *
     * The warehouse incharge works on mobile, but both users can use either
     * surface — so the same door exists here rather than forcing a phone.
     * Quantity is editable exactly as on mobile (nothing has moved yet).
     */
    public function acceptTransferRequest(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $apiRequest = new Request(['quantity' => $request->input('quantity')]);

        return $this->flashFromApi(
            app(\App\Http\Controllers\CRM\WarehouseController::class)->acceptTransferRequest($apiRequest, $id),
            'Failed to accept request.'
        );
    }

    public function declineTransferRequest(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $apiRequest = new Request(['reason' => $request->input('reason')]);

        return $this->flashFromApi(
            app(\App\Http\Controllers\CRM\WarehouseController::class)->declineTransferRequest($apiRequest, $id),
            'Failed to decline request.'
        );
    }

    /**
     * Turn a JSON API response from WarehouseController into the redirect-with-flash
     * that the web pages expect. Keeps the four methods above to one line each.
     */
    private function flashFromApi($response, string $fallbackError)
    {
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Done.');
        }
        return back()->with('error', $data['message'] ?? $fallbackError);
    }

    public function rejectTransfer(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $transfer = WarehouseTransferModel::findOrFail($id);
        
        if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
            return back()->with('error', 'Transfer is not pending.');
        }

        $reason = $request->input('reason', 'Rejected from web');

        DB::beginTransaction();
        try {
            // Return stock to warehouse
            $inventory = WarehouseInventoryModel::getOrCreate(
                $transfer->product_id,
                $transfer->product_variant_id,
                $transfer->business_unit_id
            );
            $inventory->adjustQuantity($transfer->quantity, 'adjustment',
                "Transfer rejected - stock returned: {$transfer->quantity} units. Reason: {$reason}",
                'transfer_rejected', $transfer->id);

            $transfer->update([
                'status' => WarehouseTransferModel::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            DB::commit();
            return back()->with('success', "Transfer rejected. {$transfer->quantity} units returned to warehouse.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to reject transfer: ' . $e->getMessage());
        }
    }

    /**
     * Update warehouse stock from web
     */
    public function updateStock(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity_change' => 'required|integer',
            'change_type' => 'required|in:stock_in,stock_out,adjustment,count',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantityChange = $request->input('quantity_change');
        $changeType = $request->input('change_type');
        $notes = $request->input('notes');

        if ($changeType === 'stock_out' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }
        if ($changeType === 'stock_in' && $quantityChange < 0) {
            $quantityChange = abs($quantityChange);
        }

        // 'adjustment' is signed: the modal's Add / Reduce toggle posts adjust_direction and
        // the SIGN IS APPLIED HERE, not in JavaScript — so a correction can subtract, and an
        // absent field behaves exactly as before (add).
        if ($changeType === 'adjustment' && $request->input('adjust_direction') === 'reduce' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }

        // 'count' is an absolute target.
        if ($changeType === 'count' && $quantityChange < 0) {
            return back()->with('error', 'A stock count cannot be negative.');
        }

        DB::beginTransaction();
        try {
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);

            if ($changeType === 'count') {
                $before = $inventory->quantity;
                $inventory->quantity = $quantityChange;
                $inventory->last_counted_at = now();
                $inventory->last_counted_by = auth()->id();
                $inventory->updated_by = auth()->id();
                $inventory->save();

                WarehouseInventoryLogModel::create([
                    'warehouse_inventory_id' => $inventory->id,
                    'product_id' => $productId,
                    'business_unit_id' => $businessUnitId,
                    'change_type' => 'count',
                    'quantity_before' => $before,
                    'quantity_change' => $quantityChange - $before,
                    'quantity_after' => $quantityChange,
                    'notes' => $notes ?? 'Physical stock count (web)',
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
            } else {
                // Any negative movement (stock_out, or a subtracting adjustment) must not
                // drive the warehouse below zero.
                if ($quantityChange < 0 && ($inventory->quantity + $quantityChange) < 0) {
                    DB::rollBack();
                    return back()->with('error', "Insufficient stock. Current: {$inventory->quantity}, Requested: " . abs($quantityChange));
                }
                $inventory->adjustQuantity($quantityChange, $changeType, $notes);
            }

            DB::commit();
            return back()->with('success', 'Warehouse stock updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update stock: ' . $e->getMessage());
        }
    }

    /**
     * Adjust store inventory manually (stock in / stock out / count / adjustment)
     * This modifies the product variant's inventory_quantity (store-side inventory)
     */
    public function updateStoreStock(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity_change' => 'required|integer',
            'change_type' => 'required|in:store_stock_in,store_stock_out,store_count,store_adjustment',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantityChange = $request->input('quantity_change');
        $changeType = $request->input('change_type');
        $notes = $request->input('notes');

        // Normalize quantity sign based on action
        if ($changeType === 'store_stock_out' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }
        if ($changeType === 'store_stock_in' && $quantityChange < 0) {
            $quantityChange = abs($quantityChange);
        }
        // Store-side parity with the warehouse modal: Add / Reduce toggle, sign applied here.
        if ($changeType === 'store_adjustment' && $request->input('adjust_direction') === 'reduce' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }

        DB::beginTransaction();
        try {
            $product = ProductModel::with('variants')->findOrFail($productId);

            // Resolve variant (use first variant if not specified)
            $variant = null;
            if ($variantId) {
                $variant = $product->variants->where('id', $variantId)->first();
            }
            if (!$variant) {
                $variant = $product->variants->first();
            }
            if (!$variant) {
                DB::rollBack();
                return back()->with('error', 'No product variant found for this product.');
            }

            $before = (int) $variant->inventory_quantity;

            if ($changeType === 'store_count') {
                // Set to exact quantity — an absolute target, never negative.
                if ($quantityChange < 0) {
                    DB::rollBack();
                    return back()->with('error', 'A stock count cannot be negative.');
                }
                $variant->inventory_quantity = $quantityChange;
                $actualChange = $quantityChange - $before;
            } else {
                // Stock in / stock out / adjustment. Any negative movement (stock_out, or a
                // subtracting adjustment) must not drive store stock below zero.
                if ($quantityChange < 0 && ($before + $quantityChange) < 0) {
                    DB::rollBack();
                    return back()->with('error', "Insufficient store stock. Current: {$before}, Requested: " . abs($quantityChange));
                }
                $variant->inventory_quantity = $before + $quantityChange;
                $actualChange = $quantityChange;
            }

            $variant->save();

            // Sync product.total_inventory from sum of all variants
            $product->total_inventory = $product->variants()->sum('inventory_quantity');
            $product->save();

            // Log the adjustment
            \App\Models\CRM\StoreInventoryAdjustmentModel::create([
                'product_id' => $productId,
                'product_variant_id' => $variant->id,
                'business_unit_id' => $businessUnitId,
                'change_type' => $changeType,
                'quantity_before' => $before,
                'quantity_change' => $actualChange,
                'quantity_after' => (int) $variant->inventory_quantity,
                'notes' => $notes ?? ('Manual store ' . str_replace('store_', '', $changeType)),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            DB::commit();
            return back()->with('success', 'Store inventory updated successfully. (' . $before . ' → ' . $variant->inventory_quantity . ')');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update store stock: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Failed to update store stock: ' . $e->getMessage());
        }
    }

    /**
     * Khaas Sales Report - detailed sales breakdown by product with month filter
     */
    public function salesReport(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Month filter (default: current month)
        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $monthLabel = date('F Y', strtotime($monthStart));

        // Get all Khaas product IDs
        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');

        // ─── Product-level Sales Summary with delivered/open/free breakdown ───
        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $salesQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);

        if ($excludeFree) {
            $salesQuery->where(function($q) {
                $q->whereNull('t_crm_prod_order_line_item.is_free')
                  ->orWhere('t_crm_prod_order_line_item.is_free', 0);
            });
        }

        $productSales = $salesQuery->selectRaw('t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as total_revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 0 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as paid_qty,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.line_total ELSE 0 END) as delivered_revenue')
            ->groupBy('t_crm_prod_order_line_item.product_id', 't_crm_prod_order_line_item.name')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotalRevenue = $productSales->sum('total_revenue');
        $grandTotalQty = $productSales->sum('total_qty');
        $grandTotalDeliveredQty = $productSales->sum('delivered_qty');
        $grandTotalOpenQty = $productSales->sum('open_qty');
        $grandTotalFreeQty = $productSales->sum('free_qty');
        $ordersQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            });
        if ($excludeFree) {
            $ordersQuery->where(function($q) {
                $q->whereNull('is_free')->orWhere('is_free', 0);
            });
        }
        $grandTotalOrders = $ordersQuery->distinct('order_id')->count('order_id');

        // ─── Detailed Transactions (all line items with order info) ───
        $txnQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            });
        if ($excludeFree) {
            $txnQuery->where(function($q) {
                $q->whereNull('is_free')->orWhere('is_free', 0);
            });
        }
        $transactions = $txnQuery->with(['order:id,order_number,order_date,order_status,total_price,customer_id,payment_method', 'order.customer:id,first_name,last_name,phone_original'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // ─── Available months dropdown (last 12 months) ───
        $availableMonths = [];
        for ($i = 0; $i < 12; $i++) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $availableMonths[$m] = date('F Y', strtotime($m . '-01'));
        }

        return view('khaas.sales-report', compact(
            'khaasBU', 'selectedMonth', 'monthLabel', 'availableMonths',
            'productSales', 'grandTotalRevenue', 'grandTotalQty', 'grandTotalOrders',
            'grandTotalDeliveredQty', 'grandTotalOpenQty', 'grandTotalFreeQty',
            'transactions'
        ));
    }

    /**
     * API version of sales report for mobile
     */
    public function salesReportApi(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');

        // Get selling price per product (variant price or price_min)
        $priceByProduct = [];
        $variantPrices = \DB::table('t_crm_prod_product_variant')
            ->whereIn('product_id', $khaasProductIds)
            ->where('price', '>', 0)
            ->select('product_id', \DB::raw('MIN(price) as variant_price'))
            ->groupBy('product_id')
            ->get();
        foreach ($variantPrices as $vp) {
            if ($vp->variant_price > 0) {
                $priceByProduct[(int) $vp->product_id] = round((float) $vp->variant_price, 2);
            }
        }
        $productPrices = ProductModel::whereIn('id', $khaasProductIds)->pluck('price_min', 'id');
        foreach ($productPrices as $pid => $priceMin) {
            if (!isset($priceByProduct[(int) $pid]) && $priceMin > 0) {
                $priceByProduct[(int) $pid] = round((float) $priceMin, 2);
            }
        }

        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $salesQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);

        if ($excludeFree) {
            $salesQuery->where(function($q) {
                $q->whereNull('t_crm_prod_order_line_item.is_free')
                  ->orWhere('t_crm_prod_order_line_item.is_free', 0);
            });
        }

        $productSales = $salesQuery->selectRaw('t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as total_revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupBy('t_crm_prod_order_line_item.product_id', 't_crm_prod_order_line_item.name')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotalRevenue = $productSales->sum('total_revenue');
        $totalFreeValue = 0;

        $mappedProducts = $productSales->map(function($ps) use ($grandTotalRevenue, $priceByProduct, &$totalFreeValue) {
            $pid = (int) $ps->product_id;
            $freeQty = (int) $ps->free_qty;
            $sellingPrice = $priceByProduct[$pid] ?? 0;
            $freeValue = round($freeQty * $sellingPrice, 2);
            $totalFreeValue += $freeValue;

            return [
                'product_id' => $pid,
                'name' => $ps->name,
                'total_qty' => (int) $ps->total_qty,
                'total_revenue' => (float) $ps->total_revenue,
                'order_count' => (int) $ps->order_count,
                'delivered_qty' => (int) $ps->delivered_qty,
                'open_qty' => (int) $ps->open_qty,
                'free_qty' => $freeQty,
                'free_value' => $freeValue,
                'selling_price' => $sellingPrice,
                'pct_of_total' => $grandTotalRevenue > 0 ? round(($ps->total_revenue / $grandTotalRevenue) * 100, 1) : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'month' => $selectedMonth,
            'products' => $mappedProducts,
            'totals' => [
                'revenue' => (float) $productSales->sum('total_revenue'),
                'qty' => (int) $productSales->sum('total_qty'),
                'delivered_qty' => (int) $productSales->sum('delivered_qty'),
                'open_qty' => (int) $productSales->sum('open_qty'),
                'free_qty' => (int) $productSales->sum('free_qty'),
                'free_value' => round($totalFreeValue, 2),
            ],
        ]);
    }

    /**
     * Daily sales report: day-by-day totals with per-product breakdown
     * GET /warehouse/sales-report/daily?business_unit_id=X&month=YYYY-MM
     */
    public function salesReportDaily(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');
        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $freeFilter = function($q) {
            $q->whereNull('t_crm_prod_order_line_item.is_free')
              ->orWhere('t_crm_prod_order_line_item.is_free', 0);
        };

        // Day-level totals
        $dayQuery = \App\Models\CRM\OrderLineItemModel::whereIn('t_crm_prod_order_line_item.product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);
        if ($excludeFree) $dayQuery->where($freeFilter);

        $dayTotals = $dayQuery->selectRaw('DATE(o.order_date) as sale_date,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupByRaw('DATE(o.order_date)')
            ->orderByDesc('sale_date')
            ->get();

        // Per-product per-day breakdown
        $prodQuery = \App\Models\CRM\OrderLineItemModel::whereIn('t_crm_prod_order_line_item.product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);
        if ($excludeFree) $prodQuery->where($freeFilter);

        $productByDay = $prodQuery->selectRaw('DATE(o.order_date) as sale_date,
                t_crm_prod_order_line_item.product_id,
                t_crm_prod_order_line_item.name as product_name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupByRaw('DATE(o.order_date), t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name')
            ->orderBy('product_name')
            ->get()
            ->groupBy('sale_date');

        $days = $dayTotals->map(function ($day) use ($productByDay) {
            $date = $day->sale_date;
            $products = ($productByDay[$date] ?? collect())->map(function ($p) {
                return [
                    'product_id' => (int) $p->product_id,
                    'product_name' => $p->product_name,
                    'qty' => (int) $p->total_qty,
                    'revenue' => round((float) $p->revenue, 2),
                    'delivered_qty' => (int) $p->delivered_qty,
                    'free_qty' => (int) $p->free_qty,
                ];
            })->values();

            return [
                'date' => $date,
                'total_qty' => (int) $day->total_qty,
                'revenue' => round((float) $day->revenue, 2),
                'order_count' => (int) $day->order_count,
                'delivered_qty' => (int) $day->delivered_qty,
                'open_qty' => (int) $day->open_qty,
                'free_qty' => (int) $day->free_qty,
                'products' => $products,
            ];
        });

        return response()->json([
            'success' => true,
            'month' => $selectedMonth,
            'days' => $days,
        ]);
    }

    /**
     * Web-facing daily sales AJAX endpoint (session auth)
     */
    public function salesReportDailyWeb(Request $request)
    {
        $request->merge(['business_unit_id' => optional($this->getKhaasBU())->id]);
        return $this->salesReportDaily($request);
    }

    /**
     * Daily breakdown for a product in the sales report (AJAX)
     */
    public function productDailyBreakdown(Request $request, $productId)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $dailyData = \App\Models\CRM\OrderLineItemModel::where('product_id', $productId)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd])
            ->selectRaw('DATE(o.order_date) as sale_date,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count')
            ->groupByRaw('DATE(o.order_date)')
            ->orderByDesc('sale_date')
            ->get();

        return response()->json([
            'success' => true,
            'daily' => $dailyData,
        ]);
    }

    /**
     * Initiate transfer from web
     */
    public function initiateTransfer(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantity = $request->input('quantity');
        $notes = $request->input('notes');

        // ⭐ Always resolve variant_id to product's first variant if not provided
        if (!$variantId) {
            $firstVariant = ProductVariantModel::where('product_id', $productId)->first();
            if ($firstVariant) {
                $variantId = $firstVariant->id;
            }
        }

        DB::beginTransaction();
        try {
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);

            if ($inventory->quantity < $quantity) {
                DB::rollBack();
                return back()->with('error', "Insufficient warehouse stock. Available: {$inventory->quantity}, Requested: {$quantity}");
            }

            // Create the pending transfer FIRST so the warehouse log row can carry its id in
            // reference_id (see WarehouseController::initiateTransfer for the full note).
            // Same transaction, same checks, same rollback path — only the order changed.
            $transfer = WarehouseTransferModel::create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'business_unit_id' => $businessUnitId,
                'from_location' => 'warehouse',
                'to_location' => 'store',
                'quantity' => $quantity,
                'status' => WarehouseTransferModel::STATUS_PENDING,
                'requested_by' => auth()->id(),
                'notes' => $notes,
            ]);

            // Deduct from warehouse
            $inventory->adjustQuantity(-$quantity, 'transfer',
                "Transfer to store (pending approval): {$quantity} units",
                'transfer_to_store', $transfer->id);

            DB::commit();

            // Store-transfer push is sent as a COMBINED, debounced alert by
            // FirebaseService::flushDueTransferAlerts() (triggered from the polling
            // endpoints), never one push per move — so nothing to send here.

            return back()->with('success', "Transfer of {$quantity} units initiated. Pending approval.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to initiate transfer: ' . $e->getMessage());
        }
    }

    /**
     * GET /khaas/products/{productId}/store-log
     * Returns the last N transactions that changed the store (shop) inventory for a product.
     * Combines: approved transfers IN + order line item deductions OUT + cancellation restores IN.
     * Computes a running balance so the user can trace how the current count was reached.
     */
    public function getStoreInventoryLog(Request $request, $productId)
    {
        $user = auth()->user();
        $canAccess = $this->hasKhaasAccess()
            || ($user && $user->hasMobilePermission('view_khaas_store_inventory'))
            || ($user && $user->hasMobilePermission('access_store_mode'));
        if (!$canAccess) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $product = ProductModel::with('variants')->find($productId);
        if (!$product || $product->business_unit_id !== $khaasBU->id) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $limit = (int) ($request->get('limit', 30));
        $variantIds = $product->variants->pluck('id')->toArray();

        // Current store qty. NOTE: `?:` would treat a genuine zero as "missing" and fall
        // through to the denormalised total_inventory column, showing stale stock for a
        // product that is actually sold out — and every balance below would inherit it.
        $currentStoreQty = $product->variants->isNotEmpty()
            ? (int) $product->variants->sum('inventory_quantity')
            : (int) ($product->total_inventory ?? 0);

        $events = collect();

        // ─── 1) Approved transfers TO store (INFLOW) ───
        // ⭐ `counted_by` is eager-loaded only when the column exists: the relation selects
        // counted_by, so loading it before BATCH-16 has run would blow up the whole log.
        $supportsCountedBy = WarehouseTransferModel::supportsCountedBy();
        $transfers = WarehouseTransferModel::where('product_id', $productId)
            ->where('status', 'approved')
            ->where('to_location', 'store')
            ->with('approver:id,fullname')
            ->with('requester:id,fullname')
            ->when($supportsCountedBy, fn($q) => $q->with('counter:id,fullname'))
            ->orderBy('approved_at', 'desc')
            ->limit($limit * 2) // fetch more to have enough after merge
            ->get();

        foreach ($transfers as $t) {
            // Who physically counted the stock, appended to the existing "Requested by" line.
            // Appended rather than given its own field so BOTH renderers (web store-log modal
            // and the mobile log modal, which already print sub_detail) show it without an
            // APK change. Absent => the line reads exactly as it did before.
            $subDetail = 'Requested by ' . ($t->requester->fullname ?? 'N/A');
            if ($supportsCountedBy && $t->counter) {
                $subDetail .= ' · 🔢 Counted by ' . $t->counter->fullname;
            }

            $events->push([
                'date' => $t->approved_at ?? $t->updated_at ?? $t->created_at,
                'type' => 'transfer_in',
                'label' => 'Transfer to Store',
                'change' => +$t->quantity,
                'quantity' => $t->quantity,
                'detail' => 'Approved by ' . ($t->approver->fullname ?? 'System'),
                'sub_detail' => $subDetail,
                'reference' => 'Transfer #' . $t->id,
                'icon' => '📥',
                'color' => 'green',
            ]);
        }

        // ─── 2) Order line item deductions (OUTFLOW) — inventory_deducted = 1 ───
        // These are items where inventory was deducted from the store (mark prepared / out for delivery)
        if (!empty($variantIds)) {
            $deductedItems = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->whereIn('li.variant_id', $variantIds)
                ->where('li.inventory_deducted', 1)
                ->where('o.order_status', '!=', 'cancelled') // active orders only
                ->select([
                    'li.id as line_item_id',
                    'li.order_id',
                    'li.quantity',
                    'li.name',
                    'li.updated_at',
                    'o.order_number',
                    'o.order_status',
                    'o.name as customer_name',
                ])
                ->orderBy('li.updated_at', 'desc')
                ->limit($limit * 2)
                ->get();

            foreach ($deductedItems as $item) {
                $events->push([
                    'date' => $item->updated_at,
                    'type' => 'order_deduction',
                    'label' => 'Order Deduction',
                    'change' => -$item->quantity,
                    'quantity' => $item->quantity,
                    'detail' => 'Order #' . ($item->order_number ?? $item->order_id),
                    'sub_detail' => $item->customer_name ? ('Customer: ' . $item->customer_name) : ('Status: ' . $item->order_status),
                    'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                    'icon' => '📦',
                    'color' => 'red',
                ]);
            }
        }

        // ─── 3) Cancelled orders that HAD inventory deducted (shows BOTH deduction + restoration) ───
        // When an order is cancelled after being prepared/out_for_delivery:
        //   - restoreInventory() sets inventory_deducted = 0
        //   - But the deduction DID happen earlier, so we need to show BOTH events
        // We use order status history to get accurate timestamps for each event.
        if (!empty($variantIds)) {
            $cancelledItems = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->whereIn('li.variant_id', $variantIds)
                ->where('li.inventory_deducted', 0)
                ->where('o.order_status', 'cancelled')
                // Only items that were prepared (inventory was once deducted then restored)
                ->where('li.preparation_status', 'preparing')
                ->select([
                    'li.id as line_item_id',
                    'li.order_id',
                    'li.quantity',
                    'li.name',
                    'o.updated_at as order_updated_at',
                    'o.order_number',
                    'o.name as customer_name',
                ])
                ->orderBy('o.updated_at', 'desc')
                ->limit($limit)
                ->get();

            if ($cancelledItems->isNotEmpty()) {
                // Batch-fetch status history for all cancelled orders (for accurate timestamps)
                $cancelledOrderIds = $cancelledItems->pluck('order_id')->unique()->toArray();
                $statusHistory = DB::table('t_crm_order_status_history')
                    ->whereIn('order_id', $cancelledOrderIds)
                    ->whereIn('status_code', ['out_for_delivery', 'cancelled'])
                    ->get()
                    ->groupBy('order_id');

                foreach ($cancelledItems as $item) {
                    $history = $statusHistory->get($item->order_id, collect());
                    $ofdRecord = $history->where('status_code', 'out_for_delivery')->sortByDesc('changed_at')->first();
                    $cancelRecord = $history->where('status_code', 'cancelled')->sortByDesc('changed_at')->first();

                    $deductionDate = $ofdRecord->changed_at ?? $item->order_updated_at;
                    $cancellationDate = $cancelRecord->changed_at ?? $item->order_updated_at;

                    // Event A: The deduction that happened when the order was prepared/sent out
                    $events->push([
                        'date' => $deductionDate,
                        'type' => 'order_deduction',
                        'label' => 'Order Deduction',
                        'change' => -$item->quantity,
                        'quantity' => $item->quantity,
                        'detail' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'sub_detail' => ($item->customer_name ? ('Customer: ' . $item->customer_name) : '') . ' · Later cancelled',
                        'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'icon' => '📦',
                        'color' => 'red',
                    ]);

                    // Event B: The restoration when the order was cancelled
                    $events->push([
                        'date' => $cancellationDate,
                        'type' => 'cancellation_restore',
                        'label' => 'Cancelled → Restored',
                        'change' => +$item->quantity,
                        'quantity' => $item->quantity,
                        'detail' => 'Order #' . ($item->order_number ?? $item->order_id) . ' cancelled',
                        'sub_detail' => $item->customer_name ? ('Customer: ' . $item->customer_name) : '',
                        'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'icon' => '🔄',
                        'color' => 'blue',
                    ]);
                }
            }
        }

        // ─── 4) Manual store inventory adjustments ───
        $storeAdjustments = \App\Models\CRM\StoreInventoryAdjustmentModel::where('product_id', $productId)
            ->where('business_unit_id', $khaasBU->id)
            ->with('creator:id,fullname')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        foreach ($storeAdjustments as $adj) {
            $typeLabel = match($adj->change_type) {
                'store_stock_in' => 'Store Stock In',
                'store_stock_out' => 'Store Stock Out',
                'store_count' => 'Store Count Set',
                'store_adjustment' => 'Store Adjustment',
                default => 'Store Adjustment',
            };
            $isPositive = $adj->quantity_change > 0;

            $events->push([
                'date' => $adj->created_at,
                'type' => 'store_adjustment',
                'label' => $typeLabel,
                'change' => $adj->quantity_change,
                // For a physical count, quantity_after IS the value the user entered
                // (updateStoreStock sets inventory to it directly). Expose it so the
                // UIs can show "Counted: N" — otherwise a count equal to the current
                // balance shows change 0 and reads like a no-op.
                'count_value' => $adj->change_type === 'store_count' ? (int) $adj->quantity_after : null,
                'quantity' => abs($adj->quantity_change),
                'detail' => 'By ' . ($adj->creator->fullname ?? 'User #' . $adj->created_by),
                'sub_detail' => $adj->notes ? ('📝 ' . $adj->notes) : ($adj->quantity_before . ' → ' . $adj->quantity_after),
                'reference' => 'Adjustment #' . $adj->id,
                'icon' => $isPositive ? '🏪' : '🏪',
                'color' => $isPositive ? 'purple' : 'orange',
            ]);
        }

        // ─── Sort by date (newest first) and take the limit ───
        $events = $events->sortByDesc('date')->take($limit)->values();

        // ─── Compute running balance (walk backward from current qty) ───
        $runningBalance = $currentStoreQty;
        $eventsWithBalance = [];

        foreach ($events as $event) {
            $event['balance_after'] = $runningBalance;
            $runningBalance = $runningBalance - $event['change'];
            $event['balance_before'] = $runningBalance;
            $parsedDate = $event['date'] ? \Carbon\Carbon::parse($event['date']) : null;
            $event['date_formatted'] = $parsedDate ? $parsedDate->format('M d, h:i A') : 'Unknown';
            $event['date_ago'] = $parsedDate ? $parsedDate->diffForHumans() : '';
            $event['date_day'] = $parsedDate ? $parsedDate->toDateString() : 'unknown';
            $event['date_day_label'] = $parsedDate ? ($parsedDate->isToday() ? 'Today' : ($parsedDate->isYesterday() ? 'Yesterday' : $parsedDate->format('D, M d'))) : 'Unknown';
            $eventsWithBalance[] = $event;
        }

        // Group events by day for structured display
        $days = [];
        foreach ($eventsWithBalance as $ev) {
            $dayKey = $ev['date_day'];
            if (!isset($days[$dayKey])) {
                $days[$dayKey] = [
                    'date' => $dayKey,
                    'label' => $ev['date_day_label'],
                    'events' => [],
                ];
            }
            $days[$dayKey]['events'][] = $ev;
        }
        // Add opening/closing balance per day
        foreach ($days as &$day) {
            $dayEvents = $day['events'];
            $day['closing_balance'] = $dayEvents[0]['balance_after'];
            $day['opening_balance'] = end($dayEvents)['balance_before'];
            $day['net_change'] = $day['closing_balance'] - $day['opening_balance'];
        }
        unset($day);

        return response()->json([
            'success' => true,
            'product_name' => $product->title,
            'current_store_qty' => $currentStoreQty,
            'events' => $eventsWithBalance,
            'days' => array_values($days),
            'events_count' => count($eventsWithBalance),
        ]);
    }

    /**
     * GET /khaas/products/{productId}/warehouse-log   (web)
     * GET /warehouse/products/{productId}/warehouse-log  (api)
     *
     * The COMPLETE warehouse in/out for one product, straight out of
     * t_crm_warehouse_inventory_log — which is a real append-only ledger: every row
     * already carries quantity_before / quantity_change / quantity_after / created_by,
     * and every single write site goes through WarehouseInventoryModel::adjustQuantity()
     * or one explicit log insert. So unlike the STORE log (getStoreInventoryLog above,
     * which has no ledger table and must reconstruct history by walking backwards from
     * the current quantity), here the balances are READ, not derived — they cannot drift.
     *
     * Cursor-paged with `before_id` so "Load more" can walk the whole history; the old
     * /warehouse/product-history endpoint (hard-capped at 5 merged events) is left
     * untouched for older APKs.
     */
    public function getWarehouseInventoryLog(Request $request, $productId)
    {
        // Same access triple as getStoreInventoryLog — this endpoint is new, so nobody
        // can lose access; matching the sibling avoids a surprise 403 for store users.
        $user = auth()->user();
        $canAccess = $this->hasKhaasAccess()
            || ($user && $user->hasMobilePermission('view_khaas_store_inventory'))
            || ($user && $user->hasMobilePermission('access_store_mode'));
        if (!$canAccess) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $product = ProductModel::find($productId);
        if (!$product || (int) $product->business_unit_id !== (int) $khaasBU->id) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $limit = (int) $request->get('limit', 30);
        $limit = max(1, min(100, $limit));
        $beforeId = $request->get('before_id');

        // Chip filters map straight onto the change_type ENUM
        // ('stock_in','stock_out','adjustment','transfer','count').
        $typeFilter = $request->get('type');
        $allowedTypes = ['stock_in', 'stock_out', 'adjustment', 'transfer', 'count'];
        if ($typeFilter && !in_array($typeFilter, $allowedTypes, true)) {
            $typeFilter = null;
        }

        // Current warehouse qty = sum of this product's warehouse rows (the products page
        // aggregates the same way).
        $inventoryRows = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->get(['id', 'quantity', 'unit']);
        $currentWarehouseQty = (int) $inventoryRows->sum('quantity');
        $unit = $inventoryRows->first()->unit ?? 'pcs';

        // If a product somehow has more than one warehouse row, the per-row balances in the
        // log describe only the row that changed, so they will NOT add up to the product
        // total. Flag it rather than silently showing numbers that don't reconcile.
        $multiRowWarning = $inventoryRows->count() > 1;

        $query = WarehouseInventoryLogModel::where('product_id', $productId)
            ->where('business_unit_id', $khaasBU->id);
        if ($typeFilter) {
            $query->where('change_type', $typeFilter);
        }
        if ($beforeId) {
            $query->where('id', '<', (int) $beforeId);
        }

        // Fetch one extra row to detect "has more" without a second COUNT query.
        $rows = $query->orderBy('id', 'desc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        // ─── Resolve display names for created_by in one query ───
        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->toArray();
        $userNames = [];
        if (!empty($userIds)) {
            $userNames = DB::table('t_sys_user')->whereIn('id', $userIds)->pluck('fullname', 'id')->toArray();
        }

        // ─── Link transfer rows to their transfer record ───
        // New rows carry reference_id (see initiateTransfer). Historical rows were logged
        // BEFORE the transfer row existed, so reference_id is NULL for all of them; for
        // those we fall back to an unambiguous match on (quantity, timestamp within 120s).
        // If the match is ambiguous we deliberately leave the row unlinked rather than guess.
        $transferIds = $rows->where('change_type', 'transfer')->pluck('reference_id')->filter()
            ->merge($rows->where('reference_type', 'transfer_rejected')->pluck('reference_id')->filter())
            ->unique()->values()->toArray();

        $productTransfers = WarehouseTransferModel::where('product_id', $productId)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester:id,fullname', 'approver:id,fullname', 'rejecter:id,fullname', 'counter:id,fullname'])
            ->get();
        $transfersById = $productTransfers->keyBy('id');

        $resolveTransfer = function ($log) use ($transfersById, $productTransfers) {
            if ($log->reference_id && $transfersById->has($log->reference_id)) {
                return $transfersById->get($log->reference_id);
            }
            if (!$log->created_at) {
                return null;
            }
            $qty = abs((int) $log->quantity_change);
            $logTs = $log->created_at->timestamp;
            $candidates = $productTransfers->filter(function ($t) use ($qty, $logTs) {
                if ((int) $t->quantity !== $qty || !$t->created_at) {
                    return false;
                }
                return abs($t->created_at->timestamp - $logTs) <= 120;
            });
            return $candidates->count() === 1 ? $candidates->first() : null;
        };

        // ─── Batch numbers for batch-sourced stock-ins ───
        $batchIds = $rows->where('reference_type', 'batch')->pluck('reference_id')->filter()->unique()->values()->toArray();
        $batchNumbers = [];
        if (!empty($batchIds)) {
            $batchNumbers = \App\Models\CRM\ProductBatchModel::whereIn('id', $batchIds)
                ->pluck('batch_number', 'id')->toArray();
        }

        $statusLabels = [
            'pending' => 'awaiting acceptance',
            'approved' => 'accepted into store',
            'rejected' => 'rejected',
        ];

        $events = [];
        foreach ($rows as $log) {
            $change = (int) $log->quantity_change;
            $byName = $log->created_by ? ($userNames[$log->created_by] ?? ('User #' . $log->created_by)) : 'System';
            $subParts = [];
            $countValue = null;

            switch ($log->change_type) {
                case 'stock_in':
                    if ($log->reference_type === 'batch') {
                        $label = 'Batch Production';
                        $icon = '🏭';
                        $color = 'teal';
                        $batchNo = $log->reference_id ? ($batchNumbers[$log->reference_id] ?? null) : null;
                        if ($batchNo) {
                            $subParts[] = 'Batch ' . $batchNo;
                        }
                    } else {
                        $label = 'Stock In';
                        $icon = '📥';
                        $color = 'green';
                    }
                    break;

                case 'stock_out':
                    $label = 'Stock Out';
                    $icon = '📤';
                    $color = 'red';
                    break;

                case 'transfer':
                    // Warehouse → Store. Deducted at initiation, so this row is the moment
                    // the stock physically left the warehouse.
                    $label = 'Transfer to Store';
                    $icon = '🔄';
                    $color = 'amber';
                    $t = $resolveTransfer($log);
                    if ($t) {
                        $subParts[] = 'Transfer #' . $t->id;
                        $subParts[] = $statusLabels[$t->status] ?? $t->status;
                        if ($t->status === 'approved' && $t->approver) {
                            $subParts[] = 'accepted by ' . $t->approver->fullname;
                        } elseif ($t->status === 'rejected' && $t->rejecter) {
                            $subParts[] = 'rejected by ' . $t->rejecter->fullname;
                        }
                    }
                    break;

                case 'adjustment':
                    if ($log->reference_type === 'transfer_rejected') {
                        // A rejected transfer returns stock to the warehouse. It is logged as
                        // 'adjustment' (the ENUM has no reversal type), so without this the
                        // units read as a manual correction.
                        $label = 'Transfer Rejected — Returned';
                        $icon = '↩️';
                        $color = 'blue';
                        $t = $resolveTransfer($log);
                        if ($t) {
                            $subParts[] = 'Transfer #' . $t->id;
                            if ($t->rejecter) {
                                $subParts[] = 'rejected by ' . $t->rejecter->fullname;
                            }
                        }
                    } else {
                        $label = 'Adjustment';
                        $icon = '🔧';
                        $color = 'orange';
                    }
                    break;

                case 'count':
                    $label = 'Stock Count';
                    $icon = '📊';
                    $color = 'purple';
                    // quantity_after IS the number the user typed (updateStock sets it
                    // directly), so expose it — a count equal to the current balance has
                    // change 0 and would otherwise read as a no-op.
                    $countValue = (int) $log->quantity_after;
                    break;

                default:
                    $label = ucfirst(str_replace('_', ' ', (string) $log->change_type));
                    $icon = '📦';
                    $color = 'gray';
                    break;
            }

            if (!empty($log->notes)) {
                $subParts[] = '📝 ' . $log->notes;
            }

            $parsedDate = $log->created_at ? \Carbon\Carbon::parse($log->created_at) : null;

            $events[] = [
                'id' => $log->id,
                'date' => $log->created_at,
                'type' => $log->change_type,
                'reference_type' => $log->reference_type,
                'label' => $label,
                'change' => $change,
                'quantity' => abs($change),
                'count_value' => $countValue,
                'detail' => 'By ' . $byName,
                'sub_detail' => implode(' · ', $subParts),
                'reference' => $log->reference_type
                    ? ($log->reference_type . ($log->reference_id ? ' #' . $log->reference_id : ''))
                    : ('Log #' . $log->id),
                'icon' => $icon,
                'color' => $color,
                // Read straight from the ledger row — never recomputed.
                'balance_before' => (int) $log->quantity_before,
                'balance_after' => (int) $log->quantity_after,
                'date_formatted' => $parsedDate ? $parsedDate->format('M d, h:i A') : 'Unknown',
                'date_ago' => $parsedDate ? $parsedDate->diffForHumans() : '',
                'date_day' => $parsedDate ? $parsedDate->toDateString() : 'unknown',
                'date_day_label' => $parsedDate
                    ? ($parsedDate->isToday() ? 'Today' : ($parsedDate->isYesterday() ? 'Yesterday' : $parsedDate->format('D, M d')))
                    : 'Unknown',
            ];
        }

        // Continuity marker: within this page, a row whose quantity_before does not equal the
        // NEXT-OLDER row's quantity_after means the chain is broken (legacy gap, or a filter
        // is hiding rows). Only meaningful on an unfiltered page.
        if (!$typeFilter) {
            for ($i = 0; $i < count($events) - 1; $i++) {
                if ($events[$i]['balance_before'] !== $events[$i + 1]['balance_after']) {
                    $events[$i]['gap'] = true;
                }
            }
        }

        // Group by day (newest first) with opening / closing / net per day.
        $days = [];
        foreach ($events as $ev) {
            $dayKey = $ev['date_day'];
            if (!isset($days[$dayKey])) {
                $days[$dayKey] = ['date' => $dayKey, 'label' => $ev['date_day_label'], 'events' => []];
            }
            $days[$dayKey]['events'][] = $ev;
        }
        foreach ($days as &$day) {
            $dayEvents = $day['events'];
            $day['closing_balance'] = $dayEvents[0]['balance_after'];
            $day['opening_balance'] = end($dayEvents)['balance_before'];
            $day['net_change'] = $day['closing_balance'] - $day['opening_balance'];
        }
        unset($day);

        return response()->json([
            'success' => true,
            'product_name' => $product->title,
            'unit' => $unit,
            'current_warehouse_qty' => $currentWarehouseQty,
            'multi_row_warning' => $multiRowWarning,
            'events' => $events,
            'days' => array_values($days),
            'events_count' => count($events),
            'has_more' => $hasMore,
            'next_before_id' => count($events) > 0 ? end($events)['id'] : null,
        ]);
    }

    /**
     * GET /khaas/account-activity/{accountId}
     *
     * Last N movements IN and OUT of one of this BU's payment accounts, with date, time and
     * who moved it — the web twin of the mobile Expenses "Recent Activity" view. Same
     * AccountActivityService, so the two can never disagree.
     *
     * Exists because the Expenses tab showed a bare balance per account with no way to see
     * what changed it: on the Frozen fund account almost every movement is a vendor_payment
     * outflow, so the number moved daily and nothing on screen explained why.
     */
    public function accountActivity(Request $request, $accountId)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        // BU guard: only this business unit's own accounts, and only ones the user may see
        // (mirrors the visibility rule used everywhere else for accounts).
        $account = \App\Models\FIN\AccountModel::where('id', $accountId)
            ->where('business_unit_id', $khaasBU->id)
            ->visibleTo(auth()->user())
            ->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not found'], 404);
        }

        $limit = (int) $request->get('limit', 10);

        return response()->json(
            ['success' => true] + (new \App\Services\FIN\AccountActivityService())->recent($account, $limit)
        );
    }

    /**
     * Khaas Meat Order — Track Orders & Place New Order
     */
    public function meatOrder(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'orders');

        // ⭐ Orders pending approval (still in the approval queue)
        $pendingApproval = \App\Models\CRM\ShopifyOrderModel::with('lineItems')
            ->where('external_source', 'khaas_storage')
            ->where(function($q) {
                $q->whereNull('converted')->orWhere('converted', 0);
            })
            ->where('khaas_business_unit_id', $khaasBU->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function($o) {
                return (object)[
                    'id' => $o->id,
                    'order_id' => null,
                    'order_number' => $o->order_number,
                    'nf_order_status' => 'pending_approval',
                    'total_amount' => $o->total_price,
                    'status' => 'pending_approval',
                    'notes' => $o->note,
                    'order_created_at' => $o->created_at,
                    'created_by' => $o->created_by,
                    'created_by_name' => $o->created_by
                        ? DB::table('t_sys_user')->where('id', $o->created_by)->value('fullname')
                        : null,
                    'items' => $o->lineItems->map(function($li) {
                        $productName = DB::table('t_crm_prod_product')->where('id', $li->product_id)->value('title');
                        return (object)[
                            'product_name' => $productName ?? $li->name,
                            'quantity' => $li->quantity,
                            'unit_price' => $li->unit_price,
                            'line_total' => $li->line_total,
                        ];
                    }),
                ];
            });

        // Storage orders with NF order status (already approved/converted)
        $orders = DB::table('t_crm_khaas_storage_order as so')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'so.order_id')
            ->where('so.khaas_business_unit_id', $khaasBU->id)
            ->select(
                'so.*',
                'o.order_number',
                'o.order_status as nf_order_status',
                'o.total_price as total_amount',
                'o.created_at as order_created_at'
            )
            ->orderByDesc('so.created_at')
            ->limit(50)
            ->get();

        // Get line items for each order
        foreach ($orders as $order) {
            $order->items = DB::table('t_crm_prod_order_line_item as oi')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'oi.product_id')
                ->where('oi.order_id', $order->order_id)
                ->select('oi.*', 'p.title as product_name')
                ->get();
            $order->created_by_name = $order->created_by
                ? DB::table('t_sys_user')->where('id', $order->created_by)->value('fullname')
                : null;
        }

        // Active orders = not yet received, not cancelled (show at top)
        // Completed/cancelled orders go below
        $pendingReceive = $orders->filter(function ($o) {
            return !in_array($o->status, ['received', 'cancelled'])
                && $o->nf_order_status !== 'cancelled';
        });
        $otherOrders = $orders->filter(function ($o) {
            return in_array($o->status, ['received', 'cancelled'])
                || $o->nf_order_status === 'cancelled';
        });

        // Configured storage products for new order
        $storageProducts = DB::table('t_crm_khaas_storage_config as sc')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
            ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'sc.source_variant_id')
            ->where('sc.khaas_business_unit_id', $khaasBU->id)
            ->where('sc.is_active', 1)
            ->select('sc.*', 'p.title as product_title', 'v.title as variant_title', 'sc.default_unit')
            ->orderBy('sc.sort_order')
            ->orderBy('p.title')
            ->get();

        // Settings (from config table, same as the mobile API's getStorageInventory)
        $vendorId = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
            ->where('business_unit_id', $khaasBU->id)->value('config_value');
        $vendorName = null;
        if ($vendorId) {
            $vendorName = \App\Models\FIN\VendorModel::where('id', $vendorId)->value('vendor_name');
        }
        $settings = (object)[
            'vendor_name' => $vendorName ?: 'Nizami Farms',
        ];

        return view('khaas.meat-order', compact(
            'khaasBU', 'activeTab', 'orders', 'pendingReceive', 'otherOrders',
            'pendingApproval', 'storageProducts', 'settings'
        ));
    }

    /**
     * Khaas Meat Order — receive a delivered order into storage
     */
    public function receiveStorageOrder($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $storageOrder = DB::table('t_crm_khaas_storage_order')->where('id', $id)
            ->where('khaas_business_unit_id', $khaasBU->id)->first();
        if (!$storageOrder || $storageOrder->status === 'received') {
            return back()->with('error', 'Order not found or already received.');
        }

        // Call the existing API method via the WarehouseController
        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $fakeRequest = new Request();
        $response = $controller->receiveStorageOrder($fakeRequest, $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Order received into storage.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to receive order.');
    }

    /**
     * Khaas Meat Order — place a new order (web form submission)
     */
    public function placeStorageOrder(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        // Filter out items with no quantity
        $items = collect($request->input('items', []))->filter(function ($item) {
            return isset($item['quantity']) && (float)$item['quantity'] > 0;
        })->values()->toArray();

        if (empty($items)) {
            return back()->with('error', 'Please enter quantity for at least one item.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'items' => $items,
            'notes' => $request->input('notes'),
        ]);
        $response = $controller->placeStorageOrder($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.meat-order', ['tab' => 'orders'])
                ->with('success', $data['message'] ?? 'Order placed successfully.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to place order.');
    }

    /**
     * Khaas Inventory — Current Stock & Production Plan
     */
    public function inventory(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'stock');

        // === CURRENT STOCK ===
        $configuredProducts = DB::table('t_crm_khaas_storage_config as sc')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
            ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'sc.source_variant_id')
            ->where('sc.khaas_business_unit_id', $khaasBU->id)
            ->where('sc.is_active', 1)
            ->select('sc.*', 'p.title as product_title', 'v.title as variant_title')
            ->orderBy('sc.sort_order')
            ->orderBy('p.title')
            ->get();

        $inventoryRows = DB::table('t_crm_khaas_storage_inventory')
            ->where('khaas_business_unit_id', $khaasBU->id)
            ->get()
            ->keyBy(function ($row) {
                return $row->source_product_id . '_' . ($row->source_variant_id ?: '0');
            });

        // Compute processing quantities — supports both old (single storage_product_id) and new (multi-recipe) demand items
        $inProgressItems = DB::table('t_crm_khaas_production_demand_item as di')
            ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('di.status', ['in_progress', 'accepted'])
            ->where('di.storage_deducted', 1)
            // Belt-and-braces: an item under a cancelled/completed plan must never be
            // counted as still "Processing" (see WarehouseController::cancelDemand).
            ->whereNotIn('d.status', ['cancelled', 'completed'])
            ->select('di.khaas_product_id', 'di.quantity_kg', 'di.storage_product_id', 'di.storage_deducted_qty')
            ->get();

        $processingQtys = [];
        foreach ($inProgressItems as $item) {
            if ($item->storage_product_id) {
                $pid = $item->storage_product_id;
                $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + (float)$item->storage_deducted_qty;
            } else {
                $recipes = DB::table('t_crm_khaas_product_recipe')
                    ->where('khaas_product_id', $item->khaas_product_id)
                    ->where('is_active', 1)
                    ->get();
                foreach ($recipes as $recipe) {
                    $pid = $recipe->storage_product_id;
                    $deductQty = round((float)$item->quantity_kg * (float)$recipe->ratio_kg, 3);
                    $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + $deductQty;
                }
            }
        }

        $stockItems = $configuredProducts->map(function ($p) use ($inventoryRows, $processingQtys) {
            $key = $p->source_product_id . '_' . ($p->source_variant_id ?: '0');
            $inv = $inventoryRows[$key] ?? null;
            return (object)[
                'config_id' => $p->id,
                'product_id' => $p->source_product_id,
                'variant_id' => $p->source_variant_id,
                'name' => $p->display_name ?: $p->product_title,
                'variant_title' => $p->variant_title,
                'unit' => $p->default_unit ?: 'kg',
                'quantity' => $inv ? round((float)$inv->quantity_on_hand, 3) : 0,
                'processing_qty' => round((float)($processingQtys[$p->source_product_id] ?? 0), 3),
                'last_received' => $inv ? $inv->last_received_at : null,
            ];
        });

        // === PRODUCTION DEMANDS ===
        $demands = DB::table('t_crm_khaas_production_demand as d')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('d.status', ['submitted', 'accepted', 'in_progress'])
            ->orderByDesc('d.created_at')
            ->limit(20)
            ->get();

        foreach ($demands as $demand) {
            $demand->items = DB::table('t_crm_khaas_production_demand_item as di')
                ->leftJoin('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->where('di.demand_id', $demand->id)
                ->select('di.*', 'kp.title as product_name')
                ->get();
            $demand->created_by_name = $demand->created_by
                ? DB::table('t_sys_user')->where('id', $demand->created_by)->value('fullname')
                : null;
        }

        // Demand history (completed/cancelled)
        $demandHistory = DB::table('t_crm_khaas_production_demand as d')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('d.status', ['completed', 'cancelled'])
            ->orderByDesc('d.created_at')
            ->limit(20)
            ->get();

        foreach ($demandHistory as $demand) {
            $demand->items = DB::table('t_crm_khaas_production_demand_item as di')
                ->leftJoin('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->where('di.demand_id', $demand->id)
                ->select('di.*', 'kp.title as product_name')
                ->get();
            $demand->created_by_name = $demand->created_by
                ? DB::table('t_sys_user')->where('id', $demand->created_by)->value('fullname')
                : null;
        }

        $pendingDemandCount = DB::table('t_crm_khaas_production_demand')
            ->where('business_unit_id', $khaasBU->id)
            ->where('status', 'submitted')
            ->count();

        // === DEMAND PRODUCTS (for create plan form) ===
        $demandProducts = collect();
        if ($activeTab === 'production') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $apiReq = new Request(['business_unit_id' => $khaasBU->id]);
            $resp = $controller->getDemandProducts($apiReq);
            $data = json_decode($resp->getContent(), true);
            $demandProducts = collect($data['products'] ?? []);
        }

        // === RECIPES (for recipes tab) ===
        $recipes = collect();
        $khaasProducts = collect();
        $storageProductsForRecipe = collect();
        $customMaterials = collect();
        if ($activeTab === 'recipes') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $apiReq = new Request(['business_unit_id' => $khaasBU->id]);
            $resp = $controller->getProductRecipes($apiReq);
            $data = json_decode($resp->getContent(), true);
            $recipes = collect($data['recipes'] ?? []);

            $khaasProducts = ProductModel::where('business_unit_id', $khaasBU->id)
                ->where('status', 'active')
                ->select('id', 'title')
                ->orderBy('title')
                ->get();

            $storageProductsForRecipe = DB::table('t_crm_khaas_storage_config as sc')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
                ->where('sc.khaas_business_unit_id', $khaasBU->id)
                ->where('sc.is_active', 1)
                ->select('sc.source_product_id as product_id', 'sc.source_variant_id as variant_id', 'sc.display_name', 'p.title as product_title')
                ->orderBy('p.title')
                ->get()
                ->map(function ($p) {
                    $p->name = $p->display_name ?: $p->product_title;
                    return $p;
                });

            try {
                $customMaterials = DB::table('t_crm_khaas_custom_material')
                    ->where('business_unit_id', $khaasBU->id)
                    ->where('is_active', 1)
                    ->orderBy('name')
                    ->get(['id', 'name', 'unit']);
            } catch (\Exception $e) {
                $customMaterials = collect();
            }
        }

        // === CONFIGURE STORAGE PRODUCTS (for config tab) ===
        $availableProducts = collect();
        $configuredProductIds = [];
        if ($activeTab === 'config') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $resp = $controller->getAvailableStorageProducts(new Request());
            $data = json_decode($resp->getContent(), true);
            $availableProducts = collect($data['products'] ?? []);

            $configuredProductIds = DB::table('t_crm_khaas_storage_config')
                ->where('khaas_business_unit_id', $khaasBU->id)
                ->where('is_active', 1)
                ->pluck('source_product_id')
                ->toArray();
        }

        // Whether this user may create a production plan (gates the New Plan button).
        $canCreateDemand = optional(auth()->user())->hasMobilePermission('create_production_demand') ?? false;

        return view('khaas.inventory', compact(
            'khaasBU', 'activeTab', 'stockItems', 'demands', 'demandHistory', 'pendingDemandCount',
            'demandProducts', 'recipes', 'khaasProducts', 'storageProductsForRecipe',
            'customMaterials', 'availableProducts', 'configuredProductIds', 'canCreateDemand'
        ));
    }

    /**
     * Khaas Inventory — create a new production demand
     */
    public function createDemand(Request $request)
    {
        // Dedicated create-plan permission (separate from approving transfers).
        if (!$this->hasKhaasAccess()
            || !optional(auth()->user())->hasMobilePermission('create_production_demand')) {
            abort(403, 'You do not have permission to create a production plan.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $items = collect($request->input('items', []))->filter(function ($item) {
            return isset($item['quantity_kg']) && (float)$item['quantity_kg'] > 0;
        })->values()->toArray();

        if (empty($items)) {
            return back()->with('error', 'Enter weight for at least one product.');
        }

        $tomorrow = now()->addDay()->toDateString();

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'demand_date' => $request->input('demand_date', $tomorrow),
            'items' => array_map(function ($i) {
                return [
                    'khaas_product_id' => $i['khaas_product_id'],
                    'quantity_kg' => (float) $i['quantity_kg'],
                ];
            }, $items),
            'notes' => $request->input('notes'),
        ]);

        $response = $controller->createDemand($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'production'])
                ->with('success', $data['message'] ?? 'Production demand created.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to create demand.');
    }

    /**
     * Khaas Inventory — accept a production demand
     */
    public function acceptDemand($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $response = $controller->acceptDemand(new Request(), $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Demand accepted.');
        }

        $msg = $data['message'] ?? 'Failed to accept demand.';
        if (!empty($data['shortages'])) {
            $msg .= ' — ' . implode(', ', $data['shortages']);
        }
        return back()->with('error', $msg);
    }

    /**
     * Khaas Inventory — cancel a production demand
     */
    public function cancelDemand($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $response = $controller->cancelDemand(new Request(), $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Demand cancelled.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to cancel demand.');
    }

    /**
     * Khaas Inventory — save recipe mapping(s). Accepts single or multiple storage_product_ids.
     */
    public function saveRecipe(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
            }
            return back()->with('error', 'Khaas business unit not found.');
        }

        $khaasProductId = $request->input('khaas_product_id');
        $storageProductId = $request->input('storage_product_id');
        $customMaterialIds = $request->input('custom_material_ids', []);
        if (!is_array($customMaterialIds)) $customMaterialIds = array_filter([$customMaterialIds]);

        // Support array of storage_product_ids for multi-select
        $storageProductIds = is_array($storageProductId) ? $storageProductId : [$storageProductId];
        $storageProductIds = array_filter($storageProductIds);

        if (empty($khaasProductId) || (empty($storageProductIds) && empty($customMaterialIds))) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Select a product and at least one raw material'], 400);
            }
            return back()->with('error', 'Select a product and at least one raw material.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $savedCount = 0;
        $lastError = null;

        foreach ($storageProductIds as $spId) {
            $apiRequest = new Request([
                'business_unit_id' => $khaasBU->id,
                'khaas_product_id' => $khaasProductId,
                'storage_product_id' => $spId,
                'storage_variant_id' => $request->input('storage_variant_id'),
            ]);

            $response = $controller->saveProductRecipe($apiRequest);
            $data = json_decode($response->getContent(), true);

            if ($data['success'] ?? false) {
                $savedCount++;
            } else {
                $lastError = $data['message'] ?? 'Failed to save';
            }
        }

        foreach ($customMaterialIds as $cmId) {
            $apiRequest = new Request([
                'business_unit_id' => $khaasBU->id,
                'khaas_product_id' => $khaasProductId,
                'custom_material_id' => $cmId,
            ]);

            $response = $controller->saveProductRecipe($apiRequest);
            $data = json_decode($response->getContent(), true);

            if ($data['success'] ?? false) {
                $savedCount++;
            } else {
                $lastError = $data['message'] ?? 'Failed to save';
            }
        }

        $message = $savedCount > 0
            ? "Saved {$savedCount} recipe mapping" . ($savedCount > 1 ? 's' : '') . '.'
            : ($lastError ?? 'Failed to save recipe.');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $savedCount > 0,
                'message' => $message,
                'saved_count' => $savedCount,
            ]);
        }

        if ($savedCount > 0) {
            return redirect()->route('khaas.inventory', ['tab' => 'recipes'])
                ->with('success', $message);
        }
        return back()->with('error', $message);
    }

    /**
     * Web-facing: create a custom material (session auth)
     */
    public function saveCustomMaterialWeb(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'BU not found'], 404);
        }

        $request->merge(['business_unit_id' => $khaasBU->id]);
        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        return $controller->saveCustomMaterial($request);
    }

    /**
     * Khaas Inventory — delete a recipe mapping
     */
    public function deleteRecipe(Request $request, $recipeId)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request(['recipe_id' => $recipeId]);
        $response = $controller->deleteProductRecipe($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'recipes'])
                ->with('success', $data['message'] ?? 'Recipe mapping removed.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to remove recipe.');
    }

    /**
     * Khaas Inventory — add/remove product from storage configuration
     */
    public function updateStorageConfig(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'product_id' => $request->input('product_id'),
            'action' => $request->input('action'),
            'display_name' => $request->input('display_name'),
            'default_unit' => $request->input('default_unit', 'kg'),
        ]);

        $response = $controller->updateStorageConfig($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'config'])
                ->with('success', $data['message'] ?? 'Configuration updated.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to update configuration.');
    }

    // ======================================================
    // 📊 MONTH REVIEW — what the warehouse made, and what it cost
    // ======================================================

    /**
     * Who may see the COST half.
     *
     * The production half needs only Khaas access — those packs already show
     * on the Inventory Report that every Frozen user reads. The cost sections
     * carry staff salaries, so they sit behind their own key (granted
     * Sep-2026 to Management, Taimur, khaas/Qasim and Shabib).
     *
     * ⚠ Falls back to view_khaas_sales_report so the screen still works on an
     * install where the permission row has not been inserted yet, rather than
     * showing the whole team an empty page.
     */
    private function canSeeMonthCosts(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }
        return $user->hasMobilePermission('view_khaas_month_review')
            || $user->hasMobilePermission('view_khaas_sales_report');
    }

    /** Shared assembly for the web page and the mobile endpoint. */
    private function buildMonthReview(Request $request, int $buId): array
    {
        $svc = app(\App\Services\Khaas\FrozenMonthService::class);

        $month = $request->input('month');
        if (!\App\Services\Khaas\FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }
        $basis = $request->input('basis') === 'used' ? 'used' : 'bought';

        $data = $svc->monthReview($buId, $month, $basis);
        $data['can_see_costs'] = $this->canSeeMonthCosts();

        // Never ship money to a client that may not display it.
        if (!$data['can_see_costs']) {
            $data['costs'] = ['buckets' => [], 'grand_total' => 0, 'map_available' => false];
            $data['meat'] = [
                'rows'       => [],
                'bought_kg'  => $data['meat']['bought_kg'],
                'used_kg'    => $data['meat']['used_kg'],
                'used_value' => 0,
                'on_hand_kg' => $data['meat']['on_hand_kg'],
            ];
            foreach ([
                'product', 'fixed', 'one_time', 'unclassified', 'total_spend',
                'product_per_pack', 'fixed_per_pack', 'all_in_per_pack',
                'margin_per_pack', 'breakeven_packs', 'meat_adjustment',
            ] as $k) {
                $data['headline'][$k] = null;
            }
            // The recipe-vs-purchases block quotes vendor spend too — same gate.
            foreach (['total', 'meat', 'itemised', 'untagged', 'not_itemised'] as $k) {
                $data['recipe_vs_purchases']['purchases'][$k] = null;
            }
        }

        // ⭐ The ingredient panel has its OWN gate, deliberately narrower than the one
        // above. Owner ruling 21-Sep: Qasim sees the QUANTITIES — bought, used, what is
        // left on the shelf — and not the rupees. So the panel is never hidden; only
        // its money columns are stripped, here on the server, so no client can leak
        // what it was not sent.
        //
        // ⚠⚠ Note this is narrower than canSeeMonthCosts(), which today is
        //    (view_khaas_month_review OR view_khaas_sales_report) and therefore already
        //    includes Qasim. Nothing he sees today changes; only the NEW rupees are
        //    withheld. Tick view_khaas_costing for role 17 to give them to him.
        $data['can_see_ingredient_cost'] = $this->canSeeIngredientCost();

        if (!$data['can_see_ingredient_cost']) {
            $data = $this->stripIngredientMoney($data);
        }

        return $data;
    }

    /** Who may see the estimated ingredient cost in rupees. */
    private function canSeeIngredientCost(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('view_khaas_costing') : false;
    }

    /** Who may add ingredients and edit the recipe behind a product. */
    private function canManageRecipes(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('manage_khaas_recipes') : false;
    }

    /**
     * Remove every rupee the recipe half produced, leaving the quantities intact.
     * Keys stay present and null so a client never has to guess whether a figure is
     * missing or simply zero.
     */
    private function stripIngredientMoney(array $data): array
    {
        if (!empty($data['ingredients']['rows'])) {
            foreach ($data['ingredients']['rows'] as $i => $row) {
                foreach (['bought_cost', 'used_value', 'rate_per_base', 'rate_text'] as $k) {
                    $data['ingredients']['rows'][$i][$k] = null;
                }
            }
        }

        foreach (['bought_cost', 'used_value', 'meat_used_value', 'other_used_value'] as $k) {
            if (isset($data['ingredients']['totals'][$k])) {
                $data['ingredients']['totals'][$k] = null;
            }
        }

        if (!empty($data['product_costs']['rows'])) {
            foreach ($data['product_costs']['rows'] as $i => $row) {
                $data['product_costs']['rows'][$i]['cost'] = null;
                $data['product_costs']['rows'][$i]['cost_per_pack'] = null;
                foreach (($row['lines'] ?? []) as $j => $line) {
                    $data['product_costs']['rows'][$i]['lines'][$j]['cost'] = null;
                }
            }
        }

        // 💲 Sep-27 recipe-vs-purchases block: every rupee out, pack counts and
        //    quantities (bought / used / meat kg) stay.
        if (!empty($data['recipe_vs_purchases'])) {
            $rvp = &$data['recipe_vs_purchases'];
            $rvp['recipe_cost'] = null;
            $rvp['recipe_per_pack'] = null;
            foreach (['total', 'meat', 'itemised', 'untagged', 'not_itemised'] as $k) {
                $rvp['purchases'][$k] = null;
            }
            foreach (['bought', 'used', 'difference'] as $k) {
                $rvp['ingredients'][$k] = null;
            }
            foreach ($rvp['ingredients']['rows'] as $i => $row) {
                $rvp['ingredients']['rows'][$i]['diff_value'] = null;
            }
            foreach ($rvp['meat'] as $i => $row) {
                $rvp['meat'][$i]['diff_value'] = null;
            }
            foreach ($rvp['products'] as $i => $row) {
                $rvp['products'][$i]['cost_per_pack'] = null;
                $rvp['products'][$i]['share_of_price'] = null;
            }
            unset($rvp);
        }

        $data['product_costs']['totals']['cost'] = null;
        $data['product_costs']['totals']['cost_per_pack'] = null;
        $data['headline']['ingredient_cost'] = null;
        $data['headline']['ingredient_per_pack'] = null;

        return $data;
    }

    /**
     * Web page: Frozen → Month Review.
     */
    public function monthReview(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $data = $this->buildMonthReview($request, (int) $khaasBU->id);

        // Month list: this month and the 11 before it, same shape the Sales
        // Report uses so the two screens offer the same choices.
        $availableMonths = [];
        for ($i = 0; $i < 12; $i++) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $availableMonths[$m] = date('F Y', strtotime($m . '-01'));
        }

        return view('khaas.month-review', [
            'khaasBU'         => $khaasBU,
            'review'          => $data,
            'selectedMonth'   => $data['month'],
            'monthLabel'      => date('F Y', strtotime($data['month'] . '-01')),
            'availableMonths' => $availableMonths,
            'basis'           => $data['basis'],
            'canSeeCosts'     => $data['can_see_costs'],
            'canSeeIngredientCost' => $data['can_see_ingredient_cost'],
            'canManageRecipes'     => $this->canManageRecipes(),
            'costTypes'       => \App\Models\FIN\CostTypeMapModel::types(),
        ]);
    }

    /** The same numbers for the mobile Frozen menu. */
    public function monthReviewApi(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasMobilePermission('access_khaas_mode')) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        try {
            return response()->json(['success' => true] + $this->buildMonthReview($request, (int) $khaasBU->id));
        } catch (\Exception $e) {
            \Log::error('Month review API failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to build the month review.'], 500);
        }
    }

    /**
     * Re-classify one money source (vendor / expense category / salary /
     * asset purchase) as product, fixed or one-time.
     *
     * ⭐ Writes ONLY to the map. Nothing is stamped on a ledger row, so the
     * change re-files that source's whole history the moment the page
     * reloads — and is just as easily undone.
     */
    public function setCostType(Request $request)
    {
        if (!$this->hasKhaasAccess() || !$this->canSeeMonthCosts()) {
            return response()->json(['success' => false, 'message' => 'Not allowed'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }
        if (!\App\Models\FIN\CostTypeMapModel::available()) {
            return response()->json([
                'success' => false,
                'message' => 'The cost-type table is not installed yet. Run frozen_month_review_sep2026.sql first.',
            ], 409);
        }

        $kind = (string) $request->input('source_kind');
        $key  = trim((string) $request->input('source_key'));
        $type = (string) $request->input('cost_type');

        if (!in_array($kind, \App\Models\FIN\CostTypeMapModel::kinds(), true)
            || !in_array($type, \App\Models\FIN\CostTypeMapModel::types(), true)
            || $key === '' || mb_strlen($key) > 150) {
            return response()->json(['success' => false, 'message' => 'Invalid classification.'], 422);
        }

        try {
            \App\Models\FIN\CostTypeMapModel::updateOrCreate(
                [
                    'business_unit_id' => (int) $khaasBU->id,
                    'source_kind'      => $kind,
                    'source_key'       => $key,
                ],
                [
                    'cost_type'  => $type,
                    'updated_by' => auth()->id(),
                ]
            );
            return response()->json(['success' => true, 'message' => 'Saved. Refresh to update the numbers.']);
        } catch (\Exception $e) {
            \Log::error('Failed to save cost type', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that change.'], 500);
        }
    }
}
