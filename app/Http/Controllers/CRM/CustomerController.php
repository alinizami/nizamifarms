<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerModel::query();
        
        // Exclude merged customers (they are hidden after merge)
        $query->whereNull('merged_into_customer_id');
        
        // Search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('first_name', 'like', "%{$searchTerm}%")
                  ->orWhere('last_name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                  ->orWhere('phone_original', 'like', "%{$searchTerm}%")
                  ->orWhere('phone_normalized', 'like', "%{$searchTerm}%")
                  ->orWhere('company', 'like', "%{$searchTerm}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"]);
            });
        }
        
        // Filter by city
        if ($request->has('city') && $request->city) {
            $query->where('city', $request->city);
        }

        // Filter by delivery region
        if ($request->has('region') && $request->region) {
            if ($request->region === 'none') {
                $query->whereNull('delivery_region_id');
            } else {
                $query->where('delivery_region_id', $request->region);
            }
        }
        
        // Filter by activity period (30-day or 90-day active)
        $activity = $request->get('activity', '');
        if ($activity === '30day') {
            $query->where('last_order_date', '>=', now()->subDays(30));
        } elseif ($activity === '90day') {
            $query->where('last_order_date', '>=', now()->subDays(90));
        }

        // Filter by customer type (regular / shop). Default (empty) = all.
        $customerType = $request->get('customer_type', '');
        if (in_array($customerType, [CustomerModel::TYPE_REGULAR, CustomerModel::TYPE_SHOP], true)) {
            $query->where('customer_type', $customerType);
        }

        // 💰 Account balance filter — see applyBalanceFilter().
        $balanceIds = $this->applyBalanceFilter($query, $request->get('balance', ''));

        // Sorting: support sort_by (last_order_date, total_spent) and sort_dir (asc, desc)
        $sortBy = $request->get('sort_by', 'last_order_date');
        $sortDir = $request->get('sort_dir', 'desc');

        // Validate sort parameters
        $allowedSortFields = ['last_order_date', 'total_spent', 'created_at', 'balance'];
        $allowedSortDirs = ['asc', 'desc'];
        if (!in_array($sortBy, $allowedSortFields)) $sortBy = 'last_order_date';
        if (!in_array($sortDir, $allowedSortDirs)) $sortDir = 'desc';

        // For total_spent sorting, use a subquery to sort by COMBINED total (production + history)
        // This ensures the sort order matches the displayed combined values
        if ($sortBy === 'balance') {
            $this->applyBalanceSort($query, $sortDir);
        } elseif ($sortBy === 'total_spent') {
            $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');

            $prodSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_prod_order WHERE t_crm_prod_order.customer_id = t_crm_prod_customer.id AND (external_source != 'shopify' OR external_source IS NULL) AND order_status IN ('delivered', 'completed'))";

            if ($hasHistoryTable) {
                $histSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_history_order WHERE t_crm_history_order.customer_id = t_crm_prod_customer.id AND order_status = 'delivered')";
                $query->orderByRaw("($prodSubquery + $histSubquery) $sortDir");
            } else {
                $query->orderByRaw("$prodSubquery $sortDir");
            }
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        // ⚠ Sorting by balance already ordered the rows explicitly; a second
        // orderBy would re-sort them by date and throw that away.
        if ($sortBy !== 'balance') {
            $query->orderBy('created_at', 'desc');
        }

        $customers = $query->paginate(15);
        
        // =========================================
        // HYBRID APPROACH: Add combined stats to paginated results
        // =========================================
        $customerIds = $customers->pluck('id')->toArray();
        if (!empty($customerIds)) {
            $combinedStats = $this->getCombinedCustomerStats($customerIds);
            
            // Per-customer receivable (Outstanding for shops / Pending approval
            // for regulars) — matches the approvals page.
            $receivables = $this->getCustomerReceivables($customerIds);

            // Transform paginator items to include combined stats
            $customers->getCollection()->transform(function($customer) use ($combinedStats, $receivables) {
                $stats = $combinedStats[$customer->id] ?? null;
                if ($stats) {
                    $customer->total_orders = $stats->combined_order_count;
                    $customer->total_spent = $stats->combined_total_spent;
                }
                $this->applyReceivable($customer, $receivables);
                return $customer;
            });
        }
        
        // Get unique cities for filter dropdown
        $cities = CustomerModel::whereNotNull('city')
                              ->where('city', '!=', '')
                              ->distinct()
                              ->pluck('city')
                              ->sort()
                              ->values();

        // Get delivery regions for filter dropdown
        $regions = DB::table('t_ops_delivery_region')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code']);

        // Build region name map for customer list
        $regionMap = $regions->pluck('name', 'id')->toArray();
        $customers->getCollection()->transform(function($customer) use ($regionMap) {
            $customer->delivery_region_name = $customer->delivery_region_id
                ? ($regionMap[$customer->delivery_region_id] ?? null) : null;
            return $customer;
        });
        
        // Get statistics
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $ninetyDaysAgo = $now->copy()->subDays(90);
        
        // 💰 What we are holding for customers, across the whole business — the
        // headline that sends a manager to the Balances page. One grouped query
        // through the service that owns the formula.
        $creditBalances = (new \App\Services\CustomerCreditService())->customerBalances();

        $stats = [
            'total_customers' => CustomerModel::count(),
            'active_30_days' => CustomerModel::where('last_order_date', '>=', $thirtyDaysAgo)->count(),
            'active_90_days' => CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)->count(),
            'balance_total' => round(array_sum($creditBalances), 2),
            'balance_customers' => count($creditBalances),
        ];

        return view('pages.customers.index', compact('customers', 'cities', 'regions', 'stats'));
    }
    
    public function show(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // =========================================
            // Get COMBINED orders (Production + History)
            // =========================================
            
            // 1. Get production orders (non-Shopify)
            $prodOrders = DB::table('t_crm_prod_order')
                ->where('customer_id', $id)
                ->where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->select(
                    'id', 'order_number', 'order_date', 'order_status', 
                    'external_source', 'total_price',
                    DB::raw("'production' as source_type")
                )
                ->get();
            
            // 2. Get history orders (if table exists)
            $historyOrders = collect();
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyOrders = DB::table('t_crm_history_order')
                    ->where('customer_id', $id)
                    ->select(
                        'id', 'order_number', 'order_date', 'order_status',
                        'external_source', 'total_price',
                        DB::raw("'history' as source_type")
                    )
                    ->get();
            }
            
            // 3. Combine and sort by date (newest first), take last 10
            $allOrders = $prodOrders->concat($historyOrders)
                ->sortByDesc('order_date')
                ->take(10)
                ->values();
            
            // =========================================
            // Calculate COMBINED stats (Prod + History)
            // =========================================
            
            // Production stats (non-Shopify, delivered/completed)
            $prodStats = DB::table('t_crm_prod_order')
                ->where('customer_id', $id)
                ->where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereIn('order_status', ['delivered', 'completed'])
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_price), 0) as total_spent')
                ->first();
            
            // History stats (delivered only)
            $historyStats = (object)['order_count' => 0, 'total_spent' => 0];
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyStats = DB::table('t_crm_history_order')
                    ->where('customer_id', $id)
                    ->where('order_status', 'delivered')
                    ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_price), 0) as total_spent')
                    ->first();
            }
            
            // Combined totals
            $combinedOrderCount = ($prodStats->order_count ?? 0) + ($historyStats->order_count ?? 0);
            $combinedTotalSpent = ($prodStats->total_spent ?? 0) + ($historyStats->total_spent ?? 0);
            
            // Override customer stats with combined values for display
            $customer->total_orders = $combinedOrderCount;
            $customer->total_spent = $combinedTotalSpent;
            $customer->orders = $allOrders; // Set combined orders for the modal
            
            // Add verified location metadata
            $verifiedLocation = null;
            if ($customer->verified_location_url || ($customer->latitude && $customer->longitude)) {
                $verifiedLocation = [
                    'latitude' => $customer->latitude,
                    'longitude' => $customer->longitude,
                    'url' => $customer->verified_location_url,
                    'google_maps_url' => $customer->verified_location_url ?:
                        ($customer->latitude && $customer->longitude ?
                            "https://www.google.com/maps?q={$customer->latitude},{$customer->longitude}" : null),
                    'saved_by' => \App\Models\CRM\CustomerModel::verifierLabel($customer->verified_location_saved_by),
                    'saved_at' => $customer->verified_location_saved_at,
                    // Verified-pin lock (Jul-2026): locked-for-riders state + any
                    // live unlock grant, so the UI can show 🔒/🔓 and the buttons.
                    'pin_locked' => $customer->isVerifiedPinLocked(),
                    'unlock_active' => $customer->verifiedPinUnlockActive(),
                    'unlocked_until' => $customer->verifiedPinUnlockActive()
                        ? $customer->verified_pin_unlocked_until->toIso8601String() : null,
                    'unlocked_by' => $customer->verifiedPinUnlockActive()
                        ? \App\Models\CRM\CustomerModel::verifierLabel($customer->verified_pin_unlocked_by) : null,
                ];
            }

            // Attribution for the postal address + name/email when the customer
            // app last wrote them (so an operator can see "the customer changed
            // this"). Mirrors the verified-location pattern; verifierLabel()
            // turns the customer sentinel into "Customer", else the ops user.
            $addressAttribution = $customer->address_saved_by ? [
                'saved_by' => \App\Models\CRM\CustomerModel::verifierLabel($customer->address_saved_by),
                'saved_at' => $customer->address_saved_at,
            ] : null;
            $profileAttribution = $customer->profile_saved_by ? [
                'saved_by' => \App\Models\CRM\CustomerModel::verifierLabel($customer->profile_saved_by),
                'saved_at' => $customer->profile_saved_at,
            ] : null;

            // =========================================
            // Get customers that were MERGED INTO this one
            // =========================================
            $mergedCustomers = DB::table('t_crm_prod_customer')
                ->where('merged_into_customer_id', $id)
                ->select('id', 'first_name', 'last_name', 'phone', 'phone_normalized', 'merged_at', 'merged_by')
                ->get()
                ->map(function($merged) {
                    // Get merger's name
                    $mergerName = DB::table('t_sys_user')->where('id', $merged->merged_by)->value('fullname');
                    return [
                        'id' => $merged->id,
                        'name' => trim(($merged->first_name ?? '') . ' ' . ($merged->last_name ?? '')),
                        'phone' => $merged->phone,
                        'phone_normalized' => $merged->phone_normalized,
                        'merged_at' => $merged->merged_at,
                        'merged_by_name' => $mergerName,
                    ];
                });
            
            $deliveryRegionName = null;
            if (!empty($customer->delivery_region_id)) {
                $deliveryRegionName = \DB::table('t_ops_delivery_region')
                    ->where('id', $customer->delivery_region_id)
                    ->value('name');
            }

            return response()->json([
                'success' => true,
                'customer' => $customer,
                'verified_location' => $verifiedLocation,
                // Aug-2026 — "the default is Online, set by Taimur". Same payload
                // shape every surface uses; null when no default is set.
                'default_payment_info' => \App\Models\CRM\CustomerModel::defaultPaymentMethodInfo($customer),
                'address_attribution' => $addressAttribution,
                'profile_attribution' => $profileAttribution,
                'merged_customers' => $mergedCustomers,
                'delivery_region_name' => $deliveryRegionName,
                'stats_breakdown' => [
                    'production' => [
                        'order_count' => $prodStats->order_count ?? 0,
                        'total_spent' => $prodStats->total_spent ?? 0
                    ],
                    'history' => [
                        'order_count' => $historyStats->order_count ?? 0,
                        'total_spent' => $historyStats->total_spent ?? 0
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found: ' . $e->getMessage()
            ], 404);
        }
    }
    
    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            $customers = \App\Models\CRM\CustomerModel::query()
                ->whereNull('merged_into_customer_id') // Exclude merged customers
                ->where(function($q) use ($query) {
                    $q->where('first_name', 'LIKE', "%{$query}%")
                      ->orWhere('last_name', 'LIKE', "%{$query}%")
                      ->orWhere('email', 'LIKE', "%{$query}%")
                      ->orWhere('phone', 'LIKE', "%{$query}%")
                      ->orWhere('phone_original', 'LIKE', "%{$query}%")
                      ->orWhere('phone_normalized', 'LIKE', "%{$query}%")
                      ->orWhere('company', 'LIKE', "%{$query}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$query}%"]);
                })
                ->limit($limit)
                ->get();
            
            $results = $customers->map(function($customer) {
                return [
                    'id' => $customer->id,
                    'name' => trim($customer->first_name . ' ' . $customer->last_name),
                    'email' => $customer->email,
                    'phone' => $customer->phone_original,
                    'notes' => $customer->notes,
                    'customer_type' => $customer->customer_type, // regular | shop (drives default online payment)
                    // Aug-2026 — remembered payment choice, so the Create-Order
                    // form can pre-select it the moment this customer is picked,
                    // plus who set it so the form can say so before it's changed.
                    'default_payment_method' => $customer->default_payment_method,
                    'default_payment_info' => \App\Models\CRM\CustomerModel::defaultPaymentMethodInfo($customer),
                    'address' => [
                        'first_name' => $customer->first_name,
                        'last_name' => $customer->last_name,
                        'company' => $customer->company,
                        'email' => $customer->email,
                        'phone' => $customer->phone_original,
                        'address1' => $customer->address1,
                        'address2' => $customer->address2,
                        'city' => $customer->city,
                        'province' => $customer->province,
                        'postal_code' => $customer->postal_code,
                        'country' => $customer->country
                    ]
                ];
            });
            
            return response()->json([
                'success' => true,
                'customers' => $results
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search customers: ' . $e->getMessage()
            ], 500);
        }
    }

    public function filter(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $city = $request->get('city', '');
            $activity = $request->get('activity', '');
            
            // Start with base query
            $query = CustomerModel::query();
            
            // Exclude merged customers (they are hidden after merge)
            $query->whereNull('merged_into_customer_id');
            
            // Apply search filter
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%")
                      ->orWhere('phone_original', 'LIKE', "%{$search}%")
                      ->orWhere('phone_normalized', 'LIKE', "%{$search}%")
                      ->orWhere('company', 'LIKE', "%{$search}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
            }
            
            // Apply city filter
            if (!empty($city)) {
                $query->where('city', $city);
            }

            // Apply region filter
            $region = $request->get('region', '');
            if (!empty($region)) {
                if ($region === 'none') {
                    $query->whereNull('delivery_region_id');
                } else {
                    $query->where('delivery_region_id', $region);
                }
            }
            
            // Apply activity filter
            if ($activity === '30day') {
                $query->where('last_order_date', '>=', now()->subDays(30));
            } elseif ($activity === '90day') {
                $query->where('last_order_date', '>=', now()->subDays(90));
            }

            // Apply customer type filter (regular / shop). Default (empty) = all.
            $customerType = $request->get('customer_type', '');
            if (in_array($customerType, [CustomerModel::TYPE_REGULAR, CustomerModel::TYPE_SHOP], true)) {
                $query->where('customer_type', $customerType);
            }

            // 💰 Account balance filter — same helper as index(), so the search
            // path and the paginated path can never disagree about who has money.
            $this->applyBalanceFilter($query, $request->get('balance', ''));

            // Sorting
            $sortBy = $request->get('sort_by', 'last_order_date');
            $sortDir = $request->get('sort_dir', 'desc');
            $allowedSortFields = ['last_order_date', 'total_spent', 'created_at', 'balance'];
            $allowedSortDirs = ['asc', 'desc'];
            if (!in_array($sortBy, $allowedSortFields)) $sortBy = 'last_order_date';
            if (!in_array($sortDir, $allowedSortDirs)) $sortDir = 'desc';

            if ($sortBy === 'balance') {
                $this->applyBalanceSort($query, $sortDir);
            }
            // For total_spent sorting, use a subquery to sort by COMBINED total (production + history)
            elseif ($sortBy === 'total_spent') {
                $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
                
                $prodSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_prod_order WHERE t_crm_prod_order.customer_id = t_crm_prod_customer.id AND (external_source != 'shopify' OR external_source IS NULL) AND order_status IN ('delivered', 'completed'))";
                
                if ($hasHistoryTable) {
                    $histSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_history_order WHERE t_crm_history_order.customer_id = t_crm_prod_customer.id AND order_status = 'delivered')";
                    $query->orderByRaw("($prodSubquery + $histSubquery) $sortDir");
                } else {
                    $query->orderByRaw("$prodSubquery $sortDir");
                }
            } else {
                $query->orderBy($sortBy, $sortDir);
            }
            
            // Get results (limit to 100 for performance)
            // ⚠ Balance sorting already ordered the rows; adding a date order
            // after it would silently throw that ordering away.
            if ($sortBy !== 'balance') {
                $query->orderBy('created_at', 'desc');
            }
            $customers = $query->limit(100)->get();
            
            // =========================================
            // HYBRID APPROACH: Fetch combined stats efficiently
            // Physical table data + calculated order stats
            // =========================================
            $customerIds = $customers->pluck('id')->toArray();
            
            if (!empty($customerIds)) {
                // Get combined stats from both production and history in ONE query
                $combinedStats = $this->getCombinedCustomerStats($customerIds);
                $receivables = $this->getCustomerReceivables($customerIds);

                // Merge combined stats into customer objects
                $regionMap = DB::table('t_ops_delivery_region')
                    ->where('is_active', 1)
                    ->pluck('name', 'id')
                    ->toArray();

                $customers = $customers->map(function($customer) use ($combinedStats, $regionMap, $receivables) {
                    $stats = $combinedStats[$customer->id] ?? null;
                    if ($stats) {
                        $customer->total_orders = $stats->combined_order_count;
                        $customer->total_spent = $stats->combined_total_spent;
                    }
                    $customer->delivery_region_name = $customer->delivery_region_id
                        ? ($regionMap[$customer->delivery_region_id] ?? null) : null;
                    $this->applyReceivable($customer, $receivables);
                    return $customer;
                });
            }
            
            return response()->json([
                'success' => true,
                'customers' => $customers
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to filter customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get combined order stats (production + history) for multiple customers
     * Uses efficient single query to avoid N+1
     */
    private function getCombinedCustomerStats(array $customerIds): array
    {
        // Check if history table exists
        $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
        
        if ($hasHistoryTable) {
            // Combined query with UNION for both tables
            $stats = DB::select("
                SELECT 
                    customer_id,
                    SUM(order_count) as combined_order_count,
                    SUM(total_spent) as combined_total_spent
                FROM (
                    -- Production orders (non-Shopify, delivered/completed)
                    SELECT 
                        customer_id,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_price), 0) as total_spent
                    FROM t_crm_prod_order
                    WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                      AND (external_source != 'shopify' OR external_source IS NULL)
                      AND order_status IN ('delivered', 'completed')
                    GROUP BY customer_id
                    
                    UNION ALL
                    
                    -- History orders (delivered only)
                    SELECT 
                        customer_id,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_price), 0) as total_spent
                    FROM t_crm_history_order
                    WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                      AND order_status = 'delivered'
                    GROUP BY customer_id
                ) combined
                GROUP BY customer_id
            ", array_merge($customerIds, $customerIds));
        } else {
            // Only production orders if no history table
            $stats = DB::select("
                SELECT 
                    customer_id,
                    COUNT(*) as combined_order_count,
                    COALESCE(SUM(total_price), 0) as combined_total_spent
                FROM t_crm_prod_order
                WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                  AND (external_source != 'shopify' OR external_source IS NULL)
                  AND order_status IN ('delivered', 'completed')
                GROUP BY customer_id
            ", $customerIds);
        }
        
        // Key by customer_id for easy lookup
        $result = [];
        foreach ($stats as $stat) {
            $result[$stat->customer_id] = $stat;
        }

        return $result;
    }

    /**
     * Per-customer "money to chase", batched for a page of customers. The figure
     * matches the approvals page EXACTLY (verified): shops use the Shop tab's
     * order-based outstanding, regulars use the L1 tab's ledger-based pending.
     * Both are computed for every id; the caller picks by customer_type.
     *
     * @return array<int, array{shop_owed: float, shop_count: int, reg_pending: float, reg_count: int}>
     */
    /**
     * 💰 Narrow a customer query to those holding an account balance.
     *
     * `$balance` is one of: '' (no filter) · 'any' (anything above zero) ·
     * a number (that much or more) · 'pending' (has money waiting for approval).
     *
     * ⭐ The population comes from CustomerCreditService, which owns the balance
     * formula and the merge chain — this never sums the credit table itself.
     * Because the filter runs as a plain whereIn on the SAME query the
     * paginator uses, paging, search and every other filter keep working
     * together; a client-side filter would only ever have seen one page.
     *
     * @return array<int>|null the matching ids, or null when no filter applied
     */
    private function applyBalanceFilter($query, string $balance): ?array
    {
        if ($balance === '') {
            return null;
        }

        $credit = new \App\Services\CustomerCreditService();

        if ($balance === 'pending') {
            $ids = array_keys($credit->pendingForMany());
        } else {
            $min = ($balance === 'any') ? 0.01 : (float) $balance;
            $ids = array_keys($credit->customerBalances($min > 0 ? $min : 0.01));
        }

        // ⚠ An empty id list must match NOTHING. whereIn([]) does that in
        // Laravel, but the explicit [0] makes the intent unmissable — the bug
        // this guards against is a filter that silently lists every customer.
        $query->whereIn('id', $ids ?: [0]);

        return $ids;
    }

    /**
     * Order a customer query by account balance, highest (or lowest) first.
     * Only customers who hold one take part — sorting 7,000 customers by a
     * number 11 of them have would just be the default order with noise.
     */
    private function applyBalanceSort($query, string $sortDir): void
    {
        $balances = (new \App\Services\CustomerCreditService())->customerBalances();

        if (empty($balances)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $ids = array_keys($balances);              // already highest-first
        if ($sortDir === 'asc') {
            $ids = array_reverse($ids);
        }

        $query->whereIn('id', $ids)
              ->orderByRaw('FIELD(t_crm_prod_customer.id, ' . implode(',', array_map('intval', $ids)) . ')');
    }

    private function getCustomerReceivables(array $customerIds): array
    {
        $result = [];
        if (empty($customerIds)) {
            return $result;
        }
        $ph = implode(',', array_fill(0, count($customerIds), '?'));

        // SHOP outstanding — DELIVERED + online method + NOT invoice-settled.
        // Mirrors ApprovalController::getOnlineShopItems.
        $shop = DB::select("
            SELECT o.customer_id,
                   COALESCE(SUM(GREATEST(0, o.total_price - COALESCE(o.total_paid, 0))), 0) AS owed,
                   SUM(CASE WHEN (o.total_price - COALESCE(o.total_paid, 0)) > 0.01 THEN 1 ELSE 0 END) AS cnt
            FROM t_crm_prod_order o
            WHERE o.customer_id IN ($ph)
              AND o.order_status = 'delivered'
              AND o.payment_method IN ('online', 'Online', 'bank_transfer', 'card', 'online_payment')
              AND NOT EXISTS (
                  SELECT 1 FROM t_fin_ledger l
                  WHERE l.order_id = o.id AND l.transaction_type = 'invoice'
                    AND l.approval_status IN ('approved', 'pending_l2')
              )
            GROUP BY o.customer_id
        ", $customerIds);

        // REGULAR L1 pending — mode online, no request, pending/pending_l1.
        // Mirrors ApprovalController::getOnlineL1Items.
        $reg = DB::select("
            SELECT o.customer_id,
                   COALESCE(SUM(l.amount), 0) AS pending,
                   COUNT(*) AS cnt
            FROM t_fin_ledger l
            JOIN t_crm_prod_order o ON o.id = l.order_id
            WHERE o.customer_id IN ($ph)
              AND l.mode = 'online'
              AND l.request_id IS NULL
              AND l.approval_status IN ('pending', 'pending_l1')
            GROUP BY o.customer_id
        ", $customerIds);

        foreach ($customerIds as $cid) {
            $result[$cid] = ['shop_owed' => 0.0, 'shop_count' => 0, 'reg_pending' => 0.0, 'reg_count' => 0, 'credit_balance' => 0.0];
        }
        foreach ($shop as $r) {
            $result[$r->customer_id]['shop_owed'] = (float) $r->owed;
            $result[$r->customer_id]['shop_count'] = (int) $r->cnt;
        }
        foreach ($reg as $r) {
            $result[$r->customer_id]['reg_pending'] = (float) $r->pending;
            $result[$r->customer_id]['reg_count'] = (int) $r->cnt;
        }

        // 💰 Customer credit ("bucket") — batched through the SERVICE, which
        // owns the one definition of a balance (counting statuses + the merge
        // chain). This used to be a hand-written SUM here; it disagreed with
        // every other screen for merged customers because it grouped by the raw
        // customer_id. Never re-implement the sum — call the service.
        foreach ((new \App\Services\CustomerCreditService())->balancesForMany($customerIds) as $cid => $bal) {
            if (isset($result[$cid])) {
                $result[$cid]['credit_balance'] = $bal;
            }
        }

        return $result;
    }

    /**
     * Attach receivable_amount / receivable_count / receivable_label to a
     * customer from a getCustomerReceivables() map, choosing shop vs regular by
     * type. Shared by index() and filter() so both list paths stay identical.
     */
    private function applyReceivable($customer, array $receivables): void
    {
        $isShop = $customer->customer_type === CustomerModel::TYPE_SHOP;
        $rec = $receivables[$customer->id] ?? null;
        $customer->receivable_amount = $rec ? (float) ($isShop ? $rec['shop_owed'] : $rec['reg_pending']) : 0.0;
        $customer->receivable_count  = $rec ? (int) ($isShop ? $rec['shop_count'] : $rec['reg_count']) : 0;
        $customer->receivable_label  = $isShop ? 'Outstanding' : 'Pending approval';
        // 💰 Account balance we hold FOR the customer (regular customers only —
        // shops don't use the bucket, and their chip stays receivable-only).
        $customer->credit_balance = ($rec && !$isShop) ? (float) $rec['credit_balance'] : 0.0;
    }

    public function orders($id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // =========================================
            // Get COMBINED orders (Production + History)
            // =========================================
            
            // An order counts as "settled" if it was booked through the REGULAR
            // invoice-approval flow (an approved / pending_l2 invoice ledger row)
            // — the same rule the Shop approvals tab uses to exclude such orders.
            // These older orders carry total_paid = 0 even though nothing is owed,
            // so we flag them here and treat them as Settled (not Unpaid).
            $ledgerInvoice = \App\Models\FIN\LedgerModel::TYPE_INVOICE;
            $settledStatuses = "'" . \App\Models\FIN\LedgerModel::STATUS_APPROVED
                . "','" . \App\Models\FIN\LedgerModel::STATUS_PENDING_L2 . "'";
            $invoiceSettledExpr = "EXISTS(SELECT 1 FROM t_fin_ledger l WHERE l.order_id = o.id "
                . "AND l.transaction_type = '{$ledgerInvoice}' "
                . "AND l.approval_status IN ({$settledStatuses})) as invoice_settled";

            // Actual delivery date. Production orders have no delivery_date COLUMN —
            // it comes from the most recent 'delivered' status-history row, exactly
            // as OrderModel::getDeliveryDateAttribute() derives it. A correlated
            // subquery (not a join) keeps this one row per order and hits
            // idx_status_code_order (status_code, order_id, changed_at).
            $deliveredAtExpr = "(SELECT MAX(h.changed_at) FROM t_crm_order_status_history h "
                . "WHERE h.order_id = o.id AND h.status_code = 'delivered') as delivered_at_raw";

            // 1. Get production orders (non-Shopify) with line item count
            $prodOrders = DB::table('t_crm_prod_order as o')
                ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as line_items_count FROM t_crm_prod_order_line_item GROUP BY order_id) as li'), 'o.id', '=', 'li.order_id')
                ->where('o.customer_id', $id)
                ->where(function($query) {
                    $query->where('o.external_source', '!=', 'shopify')
                          ->orWhereNull('o.external_source');
                })
                ->select(
                    'o.id', 'o.order_number', 'o.order_date', 'o.order_status',
                    'o.total_price', 'o.external_source', 'o.payment_method', 'o.note',
                    // Payment tracking (for the "invoices + payment status" view).
                    'o.payment_status', 'o.total_paid',
                    DB::raw($invoiceSettledExpr),
                    DB::raw($deliveredAtExpr),
                    DB::raw('COALESCE(li.line_items_count, 0) as line_items_count'),
                    DB::raw("'production' as source_type")
                )
                ->get();
            
            // 2. Get history orders with line item count (if table exists)
            $historyOrders = collect();
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyOrders = DB::table('t_crm_history_order as o')
                    ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as line_items_count FROM t_crm_history_order_line_item GROUP BY order_id) as li'), 'o.id', '=', 'li.order_id')
                    ->where('o.customer_id', $id)
                    ->select(
                        'o.id', 'o.order_number', 'o.order_date', 'o.order_status',
                        'o.total_price', 'o.external_source', 'o.payment_method', 'o.note',
                        // Legacy history orders have no payment tracking — emit
                        // NULLs so the mapped shape matches the production rows.
                        DB::raw('NULL as payment_status'), DB::raw('NULL as total_paid'),
                        DB::raw('NULL as invoice_settled'),
                        // Legacy orders DO carry a real delivered_at column.
                        'o.delivered_at as delivered_at_raw',
                        DB::raw('COALESCE(li.line_items_count, 0) as line_items_count'),
                        DB::raw("'history' as source_type")
                    )
                    ->get();
            }
            
            // 3. Combine and sort by date (newest first)
            $allOrders = $prodOrders->concat($historyOrders)
                ->sortByDesc('order_date')
                ->values();

            $isShop = $customer->isShop();
            // Same online-method set the Shop approvals tab uses — a shop's
            // outstanding must count ONLY these (a COD shop order isn't owed
            // through this flow).
            $onlineMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];

            // ---- Regular customers: per-order online-invoice APPROVAL state ---
            // (pending_l1 / pending_l2 / approved) for the per-invoice badges.
            // The summary L1 total itself comes from getCustomerReceivables below
            // so it matches the approvals L1 tab exactly. Not built for shops.
            $approvalStateByOrder = [];
            // Per-order L1-pending LEDGER amount (not the order total — they can
            // differ). The date-range summary in the UI sums these, so a range of
            // "everything" adds up to the same figure as the all-time tile above.
            $pendingAmountByOrder = [];
            if (!$isShop) {
                $prodIds = $prodOrders->pluck('id')->all();
                if (!empty($prodIds)) {
                    $ledgerRows = \App\Models\FIN\LedgerModel::whereIn('order_id', $prodIds)
                        ->where('mode', 'online')
                        ->whereNull('request_id')
                        // Invoice-queue types only, matching the approvals page.
                        // A customer-credit grant/consume also carries order_id,
                        // and counting one here would inflate the order's
                        // "pending approval" amount with money that is not the
                        // invoice. See PaymentProofStatusService::settledOrderIds.
                        ->whereIn('transaction_type', [
                            \App\Models\FIN\LedgerModel::TYPE_INVOICE,
                            \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT,
                        ])
                        ->whereIn('approval_status', [
                            \App\Models\FIN\LedgerModel::STATUS_PENDING,
                            \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
                            \App\Models\FIN\LedgerModel::STATUS_PENDING_L2,
                            \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                        ])
                        ->get(['order_id', 'approval_status', 'amount']);
                    $rank = ['pending_l1' => 3, 'pending_l2' => 2, 'approved' => 1];
                    foreach ($ledgerRows as $lr) {
                        // 'pending' is legacy L1 — normalize so it matches the L1 tab.
                        $state = in_array($lr->approval_status, ['pending', 'pending_l1'], true)
                            ? 'pending_l1' : $lr->approval_status;
                        $existing = $approvalStateByOrder[$lr->order_id] ?? null;
                        if ($existing === null || ($rank[$state] ?? 0) > ($rank[$existing] ?? 0)) {
                            $approvalStateByOrder[$lr->order_id] = $state;
                        }
                        if ($state === 'pending_l1') {
                            // SUM, not overwrite — the L1 tab counts every pending
                            // row, and an order can carry more than one.
                            $pendingAmountByOrder[$lr->order_id] =
                                ($pendingAmountByOrder[$lr->order_id] ?? 0.0) + (float) $lr->amount;
                        }
                    }
                }
            }

            // ---- Shops: the ACTUAL payment rows behind each invoice, tagged
            // with the entry BATCH they came from. This is what lets the
            // statement answer "were these six payments one transfer or six?"
            // — a bulk shop payment is split FIFO into one row per invoice, and
            // per-invoice they otherwise look unrelated.
            // Read-only and additive: nothing here changes total_paid, the
            // balance, or any figure the tiles already show.
            $paymentsByOrder = [];
            if ($isShop) {
                $prodIds = $prodOrders->pluck('id')->all();
                if (!empty($prodIds)) {
                    $payRows = DB::table('t_crm_order_payments as p')
                        ->leftJoin('t_sys_user as u', 'u.id', '=', 'p.created_by')
                        ->leftJoin('t_fin_online_receiving_accounts as b', 'b.id', '=', 'p.receiving_account_id')
                        ->whereIn('p.order_id', $prodIds)
                        ->where('p.status', 'active')
                        ->orderBy('p.payment_date')
                        ->orderBy('p.id')
                        ->select(
                            'p.id', 'p.order_id', 'p.amount', 'p.payment_date', 'p.reference',
                            'p.created_by', 'p.created_at',
                            'u.fullname as entered_by',
                            'b.short_code as bank_code', 'b.name as bank_name'
                        )
                        ->get();

                    // The batch is resolved against ALL of a batch's slices, not
                    // just the ones listed here — an entry can legitimately cover
                    // an invoice this list excludes (Shopify-sourced orders are
                    // filtered out above), and the count must still say 7 of 7.
                    $batches = app(\App\Services\Payments\PaymentBatchService::class)
                        ->describe($payRows, (int) $id);

                    foreach ($payRows as $p) {
                        $batch = $batches[(int) $p->id] ?? null;
                        $paymentsByOrder[$p->order_id][] = [
                            'id'           => (int) $p->id,
                            'amount'       => (float) $p->amount,
                            'payment_date' => $p->payment_date
                                ? date('Y-m-d', strtotime($p->payment_date))
                                : null,
                            'reference'    => $p->reference,
                            'bank'         => $p->bank_code ?: $p->bank_name,
                            'entered_by'   => $p->entered_by,
                            'entered_at'   => $batch['entered_at'] ?? (
                                $p->created_at ? date('Y-m-d H:i:s', strtotime($p->created_at)) : null
                            ),
                            // Batch facts — is_bulk false means this transfer
                            // covered only this invoice.
                            'is_bulk'      => $batch['is_bulk'] ?? false,
                            'batch_size'   => $batch['batch_size'] ?? 1,
                            'batch_total'  => $batch['batch_total'] ?? (float) $p->amount,
                            'batch_orders' => $batch['orders'] ?? [],
                        ];
                    }
                }
            }

            // ---- Receivable summary — computed by the SAME batched helper the
            // customers LIST and the approvals page use, so the detail tile can
            // never drift from the list chip / approvals for either type. ----
            $rec = $this->getCustomerReceivables([$id])[$id]
                ?? ['shop_owed' => 0.0, 'shop_count' => 0, 'reg_pending' => 0.0, 'reg_count' => 0];
            $recAmount = $isShop ? $rec['shop_owed'] : $rec['reg_pending'];
            $recCount  = $isShop ? $rec['shop_count'] : $rec['reg_count'];

            return response()->json([
                'success' => true,
                // Shops settle invoices incrementally (order-based balance);
                // regulars settle via invoice approval (ledger state). The UI
                // shows the right column set per type via this flag.
                'is_shop' => $isShop,
                'orders' => $allOrders->map(function($order) use ($isShop, $onlineMethods, $approvalStateByOrder, $pendingAmountByOrder, $paymentsByOrder) {
                    $isProduction = $order->source_type === 'production';
                    $isDelivered  = ($order->order_status ?? '') === 'delivered';
                    $invoiceSettled = $isProduction && (int) ($order->invoice_settled ?? 0) === 1;
                    $isOnline = $isProduction && in_array($order->payment_method, $onlineMethods, true);
                    $rawPaid = ($isProduction && $order->total_paid !== null) ? (float) $order->total_paid : 0.0;

                    $paymentStatus = null; $displayPaid = null; $balanceRemaining = null; $approvalState = null;

                    if ($isShop) {
                        // Per-invoice payment state for shop online orders.
                        if (!$isProduction) {
                            // legacy history — leave blank
                        } elseif ($invoiceSettled) {
                            $paymentStatus = 'settled'; $displayPaid = (float) $order->total_price; $balanceRemaining = 0.0;
                        } elseif ($isOnline && $isDelivered) {
                            $paymentStatus = $order->payment_status ?? 'unpaid';
                            $displayPaid = $rawPaid;
                            $balanceRemaining = max(0, (float) $order->total_price - $rawPaid);
                        }
                        // else: non-online / not-delivered shop order → '—'
                    }

                    $pendingAmount = null;
                    if (!$isShop) {
                        // Regular: online-invoice approval state (pending_l1/l2/approved).
                        $approvalState = $isProduction ? ($approvalStateByOrder[$order->id] ?? null) : null;
                        $pendingAmount = $isProduction ? ($pendingAmountByOrder[$order->id] ?? null) : null;
                    }

                    // ---- Delivery date -------------------------------------
                    // Mirrors OrderModel::getDeliveryDateAttribute() so the web
                    // statement and the mobile app can never disagree: only
                    // delivered/completed orders have one, it's the real
                    // 'delivered' timestamp when we have it, and it falls back to
                    // the order date when we don't. Emitted as 'Y-m-d' (never a
                    // Carbon date cast) to avoid the UTC off-by-one on display.
                    // NOTE: all 'completed' orders currently hit the fallback —
                    // they have no 'delivered' status-history row.
                    $deliveryDate = null;
                    if (in_array($order->order_status ?? '', ['delivered', 'completed'], true)) {
                        $deliveryDate = !empty($order->delivered_at_raw)
                            ? date('Y-m-d', strtotime($order->delivered_at_raw))
                            : ($order->order_date ? date('Y-m-d', strtotime($order->order_date)) : null);
                    }

                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_date' => $order->order_date,
                        'delivery_date' => $deliveryDate,
                        'order_status' => $order->order_status,
                        'total_price' => $order->total_price,
                        'payment_status' => $paymentStatus,
                        'total_paid' => $displayPaid,
                        'balance_remaining' => $balanceRemaining,
                        'approval_state' => $approvalState,
                        // L1-pending ledger amount for this order (regulars only).
                        'pending_amount' => $pendingAmount,
                        // The payment rows behind 'total_paid', each tagged with
                        // the entry batch it belongs to (shops only; [] for a
                        // regular customer and for legacy history orders).
                        'payments' => ($isProduction && $isShop)
                            ? ($paymentsByOrder[$order->id] ?? [])
                            : [],
                        'line_items_count' => $order->line_items_count,
                        'external_source' => $order->external_source ?? 'csv_import',
                        'payment_method' => $order->payment_method,
                        'notes' => $order->note ?? null,
                        'source_type' => $order->source_type // 'production' or 'history'
                    ];
                }),
                'summary' => [
                    'production_orders' => $prodOrders->count(),
                    'history_orders' => $historyOrders->count(),
                    'total_orders' => $allOrders->count(),
                    // Shop figures (order-based) + regular figures (ledger L1),
                    // both from getCustomerReceivables so they equal the list chip.
                    'outstanding_balance' => (float) $rec['shop_owed'],
                    'unpaid_count' => (int) $rec['shop_count'],
                    'pending_approval_amount' => (float) $rec['reg_pending'],
                    'pending_approval_count' => (int) $rec['reg_count'],
                    // Unified "money to chase" figure the UI shows directly — it
                    // matches the Shop tab (shops) / L1 tab (regulars).
                    'receivable_label'  => $isShop ? 'Outstanding' : 'Pending approval',
                    'receivable_amount' => (float) $recAmount,
                    'receivable_count'  => (int) $recCount,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Error fetching customer orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check if phone number already exists (for duplicate detection)
     * Used by mobile app before creating new customer
     */
    public function checkPhone(Request $request)
    {
        try {
            $phone = $request->get('phone', '');
            
            if (empty($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number is required'
                ], 400);
            }
            
            // Normalize the phone number (same logic as CustomerModel::normalizePhone)
            $digits = preg_replace('/\D/', '', $phone);
            $normalizedPhone = substr($digits, -10);
            
            // Ensure we have exactly 10 digits
            if (strlen($normalizedPhone) < 10) {
                $normalizedPhone = str_pad($normalizedPhone, 10, '0', STR_PAD_LEFT);
            }
            
            // Check if customer exists with this normalized phone
            $existingCustomer = CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id') // Exclude merged customers
                ->first();
            
            if ($existingCustomer) {
                return response()->json([
                    'success' => true,
                    'exists' => true,
                    'customer' => [
                        'id' => $existingCustomer->id,
                        'name' => trim(($existingCustomer->first_name ?? '') . ' ' . ($existingCustomer->last_name ?? '')),
                        'phone' => $existingCustomer->phone_original ?? $existingCustomer->phone,
                        'city' => $existingCustomer->city,
                    ],
                    'normalized_phone' => $normalizedPhone,
                ]);
            }
            
            return response()->json([
                'success' => true,
                'exists' => false,
                'normalized_phone' => $normalizedPhone,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking phone: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a new customer (for mobile app)
     * Uses same normalization logic as findOrCreateByPhone
     */
    public function store(Request $request)
    {
        try {
            // Validate required fields
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'required|string|max:50',
                'email' => 'nullable|email|max:255',
                'company' => 'nullable|string|max:255',
                'customer_type' => 'nullable|string|in:regular,shop',
                'address1' => 'nullable|string|max:500',
                'address2' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'province' => 'nullable|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'notes' => 'nullable|string',
            ]);
            
            // Normalize phone number (same logic as CustomerModel::normalizePhone)
            $phoneData = CustomerModel::normalizePhone($validated['phone']);
            $normalizedPhone = $phoneData['normalized'];
            $originalPhone = $phoneData['original'];
            
            // Check for duplicate (normalized phone must be unique)
            $existingCustomer = CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id')
                ->first();
            
            if ($existingCustomer) {
                $existingName = trim(($existingCustomer->first_name ?? '') . ' ' . ($existingCustomer->last_name ?? ''));
                return response()->json([
                    'success' => false,
                    'message' => "A customer with this phone number already exists: {$existingName}",
                    'existing_customer' => [
                        'id' => $existingCustomer->id,
                        'name' => $existingName,
                        'phone' => $existingCustomer->phone_original ?? $existingCustomer->phone,
                    ]
                ], 409); // 409 Conflict
            }
            
            // Create the customer (uses auto-increment ID from database)
            $customer = CustomerModel::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $normalizedPhone, // Legacy field - store normalized
                'phone_normalized' => $normalizedPhone,
                'phone_original' => $originalPhone,
                'email' => $validated['email'] ?? null,
                'company' => $validated['company'] ?? null,
                'customer_type' => $validated['customer_type'] ?? CustomerModel::TYPE_REGULAR,
                'address1' => $validated['address1'] ?? null,
                'address2' => $validated['address2'] ?? null,
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'country' => $validated['country'] ?? 'Pakistan',
                'notes' => $validated['notes'] ?? null,
                'is_active' => 1,
                'total_orders' => 0,
                'total_spent' => 0,
                'created_by' => auth()->check() ? auth()->id() : null,
            ]);
            
            \Log::info('Customer created via mobile app', [
                'customer_id' => $customer->id,
                'phone_normalized' => $normalizedPhone,
                'created_by' => auth()->id()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'customer' => [
                    'id' => $customer->id,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'phone' => $customer->phone_original,
                    'phone_normalized' => $customer->phone_normalized,
                    'email' => $customer->email,
                    'company' => $customer->company,
                    'customer_type' => $customer->customer_type,
                    'address1' => $customer->address1,
                    'address2' => $customer->address2,
                    'city' => $customer->city,
                    'province' => $customer->province,
                    'country' => $customer->country,
                    'notes' => $customer->notes,
                    'total_orders' => 0,
                    'total_spent' => 0,
                ]
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to create customer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get history order details with line items
     * For viewing legacy/history orders from customer modal
     */
    public function historyOrderDetails($historyOrderId)
    {
        try {
            // Check if history table exists
            if (!DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                return response()->json([
                    'success' => false,
                    'message' => 'History tables not available'
                ], 404);
            }
            
            // Get order details
            $order = DB::table('t_crm_history_order')
                ->where('id', $historyOrderId)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'History order not found'
                ], 404);
            }
            
            // Get line items
            $lineItems = DB::table('t_crm_history_order_line_item')
                ->where('order_id', $historyOrderId)
                ->get();
            
            // Get customer info if available
            $customer = null;
            if ($order->customer_id) {
                $customer = DB::table('t_crm_prod_customer')
                    ->where('id', $order->customer_id)
                    ->select('id', 'first_name', 'last_name', 'phone', 'email', 'city')
                    ->first();
            }
            
            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->order_status,
                    'order_date' => $order->order_date,
                    'delivered_at' => $order->delivered_at,
                    'name' => $order->name,
                    'contact_email' => $order->contact_email,
                    'currency' => $order->currency ?? 'PKR',
                    'subtotal_price' => $order->subtotal_price,
                    'discount_total' => $order->discount_total,
                    'shipping_total' => $order->shipping_total,
                    'total_price' => $order->total_price,
                    'address_first_name' => $order->address_first_name,
                    'address_last_name' => $order->address_last_name,
                    'address_email' => $order->address_email,
                    'address_phone' => $order->address_phone,
                    'address_line1' => $order->address_line1,
                    'address_city' => $order->address_city,
                    'coupon_code' => $order->coupon_code,
                    'payment_method' => $order->payment_method,
                    'note' => $order->note,
                    'external_source' => 'history',
                    'import_batch_id' => $order->import_batch_id,
                    'created_at' => $order->created_at
                ],
                'line_items' => $lineItems->map(function($item) {
                    return [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'line_subtotal' => $item->line_subtotal,
                        'discount_amount' => $item->discount_amount,
                        'line_total' => $item->line_total ?? $item->line_subtotal
                    ];
                }),
                'customer' => $customer
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching history order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function update(Request $request, $id)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:20',
            'company' => 'nullable|string|max:255',
            'customer_type' => 'nullable|string|in:regular,shop',
            // Aug-2026 — remembered payment choice. '' is the "No default"
            // option in the edit form and clears the column.
            'default_payment_method' => 'nullable|string|in:cash,online',
            'address1' => 'nullable|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean'
        ]);
        
        try {
            $customer = CustomerModel::findOrFail($id);
            $previousType = $customer->customer_type;
            $payload = $request->all();

            // Oct-2026 guard — saving a customer as SHOP deletes every pending
            // online invoice on their orders (they leave Online Approvals and
            // become shop-tab outstanding). On 14-Oct that ran on Irfan Burney
            // (SH-23614, Rs 53,687) from a single mis-set dropdown and the
            // invoice vanished without a word. So: when the flip would delete
            // anything, refuse once and say exactly what goes, until the form
            // comes back with confirm_shop_conversion=1. A flip that deletes
            // nothing needs no confirmation.
            $flippingToShop = $request->filled('customer_type')
                && $previousType !== CustomerModel::TYPE_SHOP
                && $request->input('customer_type') === CustomerModel::TYPE_SHOP;
            if ($flippingToShop && !$request->boolean('confirm_shop_conversion')) {
                $pending = $this->pendingOnlineInvoiceQuery($customer)
                    ->join('t_crm_prod_order as o', 'o.id', '=', 't_fin_ledger.order_id')
                    ->orderBy('t_fin_ledger.transaction_date')
                    ->get(['t_fin_ledger.id', 't_fin_ledger.amount', 'o.order_number']);
                if ($pending->isNotEmpty()) {
                    $total = (float) $pending->sum('amount');
                    $list  = $pending->map(fn ($r) => $r->order_number . ' (Rs ' . number_format((float) $r->amount) . ')')->implode(', ');
                    $msg = 'Making this customer a Shop will remove ' . $pending->count()
                        . ' pending online invoice' . ($pending->count() === 1 ? '' : 's')
                        . ' worth Rs ' . number_format($total) . ' from Online Approvals: ' . $list
                        . '. They become shop outstanding, settled by payments.';
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success'            => false,
                            'needs_confirmation' => true,
                            'message'            => $msg,
                            'pending_count'      => $pending->count(),
                            'pending_amount'     => $total,
                        ], 409);
                    }
                    return redirect()->back()->with('error', $msg . ' Tick the confirmation to proceed.');
                }
            }

            // Guard: don't allow flipping a shop back to regular while it still
            // has in-progress (partially paid) shop orders — that would leave
            // orders mid-cycle on the wrong billing basis.
            if ($request->filled('customer_type')
                && $previousType === CustomerModel::TYPE_SHOP
                && $request->input('customer_type') === CustomerModel::TYPE_REGULAR
                && $this->shopHasInProgressPayments($customer->id)) {
                $msg = 'This shop has orders with partial payments in progress. Settle or void them before switching back to regular.';
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return redirect()->back()->with('error', $msg);
            }

            // Phone is stored across THREE columns: phone (legacy, holds the
            // normalized 10-digit form), phone_normalized (used for dedup
            // lookups), and phone_original (the display value used by mobile
            // and search). The edit form only sends the user-facing 'phone'
            // input, so if we don't fan it out here the mobile app and most
            // search paths keep showing the old number even though the form
            // appeared to save successfully.
            if ($request->filled('phone')) {
                $phoneData = CustomerModel::normalizePhone($request->input('phone'));
                $newNormalized = $phoneData['normalized'];
                $newOriginal = $phoneData['original'];

                if ($newNormalized !== $customer->phone_normalized) {
                    // Dedup guard: prevent edit from colliding with another
                    // customer's normalized phone (which is the dedup key).
                    // Excludes self and any merged-away duplicates.
                    $duplicate = CustomerModel::where('phone_normalized', $newNormalized)
                        ->where('id', '!=', $customer->id)
                        ->whereNull('merged_into_customer_id')
                        ->first();
                    if ($duplicate) {
                        $dupName = trim(($duplicate->first_name ?? '') . ' ' . ($duplicate->last_name ?? ''));
                        $msg = "Phone number already belongs to another customer: {$dupName}";
                        if ($request->expectsJson()) {
                            return response()->json([
                                'success' => false,
                                'message' => $msg,
                                'existing_customer' => [
                                    'id' => $duplicate->id,
                                    'name' => $dupName,
                                    'phone' => $duplicate->phone_original ?? $duplicate->phone,
                                ],
                            ], 409);
                        }
                        return redirect()->back()->with('error', $msg);
                    }
                }

                // Override the request payload with all three columns so the
                // mass-assign update() below writes them in one go.
                $payload['phone'] = $newNormalized;
                $payload['phone_normalized'] = $newNormalized;
                $payload['phone_original'] = $newOriginal;
            }

            // Aug-2026 — the default payment method is written by its own helper
            // (value + attribution together), never through this mass update, so
            // it is stripped from the payload here and applied after the save.
            // Only touched when the caller actually sent the field, so every
            // other update path (mobile, geocode, merge) is unaffected.
            $setsDefaultPaymentMethod = $request->has('default_payment_method');
            unset($payload['default_payment_method']);

            // Snapshot the address BEFORE the write so we can tell whether it moved.
            $addressBefore = trim(($customer->address1 ?? '') . '|' . ($customer->city ?? ''));

            $customer->update($payload);

            // Aug-2026 — "No default" posts an empty string, which the helper
            // normalizes to NULL and clears the attribution alongside. The
            // stamped user is whoever saved this form.
            if ($setsDefaultPaymentMethod) {
                CustomerModel::setDefaultPaymentMethod(
                    $customer->id,
                    $request->input('default_payment_method'),
                    auth()->id()
                );
            }

            // ⭐ Address edited → the machine-geocoded pin describes the OLD address
            //    and is now simply wrong. Re-geocode so the pin and the written
            //    address always agree; otherwise a corrected address quietly keeps
            //    routing the rider to the previous place.
            //
            //    Only when there is NO verified pin: a human pin is the truth and
            //    outlives an address correction (people fix spellings and add
            //    landmarks all the time — that must not move a pin someone stood on).
            //    force=true because geocodeCustomer() skips a populated slot.
            //    Non-fatal: a geocode failure must never fail the customer save.
            $addressAfter = trim(($customer->address1 ?? '') . '|' . ($customer->city ?? ''));
            if ($addressBefore !== $addressAfter
                && !empty($customer->address1)
                && empty($customer->latitude)
                && empty($customer->longitude)) {
                try {
                    \App\Services\GeocodingService::geocodeCustomer($customer->id, true, [
                        'trigger' => 'address_edit', 'user_id' => auth()->id(),
                    ]);
                    \Log::info('Re-geocoded after address edit', [
                        'customer_id' => $customer->id,
                        'address' => $customer->address1,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Re-geocode after address edit failed (non-fatal)', [
                        'customer_id' => $customer->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // If the customer was just flipped regular -> shop, convert their
            // existing PENDING online invoices into the shop payment flow so
            // they leave the regular approval queue and appear in the Shop tab.
            $convertedCount = 0;
            if ($request->filled('customer_type')
                && $previousType !== CustomerModel::TYPE_SHOP
                && $customer->customer_type === CustomerModel::TYPE_SHOP) {
                $convertedCount = $this->convertPendingOnlineInvoicesToShopFlow($customer);
            }

            // Handle both AJAX and regular form submissions
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Customer updated successfully!',
                    'converted_invoices' => $convertedCount,
                ]);
            }
            
            return redirect()->route('customers.index')->with('success', 'Customer updated successfully!');
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error updating customer: ' . $e->getMessage()
                ], 500);
            }
            
            return redirect()->back()->with('error', 'Error updating customer: ' . $e->getMessage());
        }
    }

    /**
     * True if this (shop) customer has any order with active payments recorded
     * but not yet fully paid — i.e. a billing cycle in progress that must not
     * be abandoned by flipping back to regular.
     */
    private function shopHasInProgressPayments($customerId): bool
    {
        return \App\Models\CRM\OrderModel::where('customer_id', $customerId)
            ->where('payment_status', 'partial')
            ->exists();
    }

    /**
     * Convert a newly-flagged shop customer's PENDING online invoices out of
     * the regular approval queue and into the shop payment flow.
     *
     * For each of the customer's online orders that still has a *pending*
     * invoice ledger row (mode=online, transaction_type=invoice, not approved/
     * rejected): reverse any balances already applied at L1, delete the invoice
     * ledger row, and clear order.ledger_transaction_id. The order (delivered +
     * now outstanding) then surfaces in the Shop tab to be settled by payments.
     *
     * Approved invoices are left untouched — that revenue is already booked.
     * Returns the number of invoices converted.
     */
    /**
     * The invoice rows a regular→shop flip would take out of Online Approvals:
     * pending / pending_l1 online invoices on this customer's live orders.
     * ONE definition, used by the confirmation in update() and by the
     * conversion itself, so what is shown is exactly what is removed.
     */
    private function pendingOnlineInvoiceQuery(CustomerModel $customer, ?array $pendingStatuses = null)
    {
        $pendingStatuses = $pendingStatuses ?? [
            \App\Models\FIN\LedgerModel::STATUS_PENDING,
            \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
        ];

        return \App\Models\FIN\LedgerModel::query()
            ->where('t_fin_ledger.mode', \App\Models\FIN\LedgerModel::MODE_ONLINE)
            ->where('t_fin_ledger.transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
            ->whereIn('t_fin_ledger.approval_status', $pendingStatuses)
            ->whereNull('t_fin_ledger.request_id')
            ->whereIn('t_fin_ledger.order_id', function ($q) use ($customer) {
                $q->select('id')->from('t_crm_prod_order')->where('customer_id', $customer->id);
            });
    }

    private function convertPendingOnlineInvoicesToShopFlow(CustomerModel $customer): int
    {
        $converted = 0;

        // Only truly PRE-L1 invoices get converted to the shop flow. Once an
        // invoice clears L1 (status pending_l2) the amount has already been
        // posted to the ledger, so we treat it as booked/approved and LEAVE IT
        // ALONE — deleting it here would both reverse a legitimate balance and
        // make the order resurface in the Shop tab as "unpaid". The shop-tab
        // query already excludes pending_l2 / approved invoices.
        $pendingStatuses = [
            \App\Models\FIN\LedgerModel::STATUS_PENDING,
            \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
        ];

        // Pending online invoice ledger rows for this customer's orders
        // (same set the pre-save confirmation in update() shows the user).
        $invoices = $this->pendingOnlineInvoiceQuery($customer, $pendingStatuses)->get();

        foreach ($invoices as $invoice) {
            \DB::beginTransaction();
            try {
                // If L1 already posted balances, reverse them via the canonical engine (self-guards
                // on balance_updated; the exact inverse of the invoice posting) so we don't double
                // count once payments start posting. In practice the query only matches pending /
                // pending_l1 rows (never applied), so this is a safety no-op — but it stays correct
                // and consistent if a flag=1 row ever reaches here.
                (new \App\Services\FIN\BalancePostingService())->reverse($invoice);

                $orderId = $invoice->order_id;
                $invoice->delete();

                // Clear the order's invoice link so it isn't treated as posted
                // and can be settled via the shop payment flow instead.
                if ($orderId) {
                    \DB::table('t_crm_prod_order')
                        ->where('id', $orderId)
                        ->update(['ledger_transaction_id' => null]);
                }

                \DB::commit();
                $converted++;
            } catch (\Exception $e) {
                \DB::rollBack();
                \Log::error('Failed to convert pending invoice to shop flow', [
                    'customer_id' => $customer->id,
                    'ledger_id' => $invoice->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($converted > 0) {
            \Log::info('Converted pending online invoices to shop flow', [
                'customer_id' => $customer->id,
                'converted' => $converted,
            ]);
        }

        return $converted;
    }

    public function addNote(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string'
        ]);
        
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // Append new note to existing notes
            $existingNotes = $customer->notes ?? '';
            $newNote = $request->notes;
            $timestamp = now()->format('Y-m-d H:i:s');
            
            if ($existingNotes) {
                $updatedNotes = $existingNotes . "\n\n[{$timestamp}] " . $newNote;
            } else {
                $updatedNotes = "[{$timestamp}] " . $newNote;
            }
            
            $customer->update(['notes' => $updatedNotes]);
            
            return response()->json([
                'success' => true,
                'message' => 'Note added successfully!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding note: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Merge duplicate customers into a primary customer
     * - Updates all orders (prod + history) to point to primary customer
     * - Marks duplicate customers as merged (hidden from normal queries)
     * - Preserves full traceability
     */
    public function mergeCustomers(Request $request)
    {
        $validated = $request->validate([
            'primary_customer_id' => 'required|integer|exists:t_crm_prod_customer,id',
            'duplicate_customer_ids' => 'required|array|min:1',
            'duplicate_customer_ids.*' => 'integer|exists:t_crm_prod_customer,id',
        ]);
        
        try {
            $primaryId = $validated['primary_customer_id'];
            $duplicateIds = $validated['duplicate_customer_ids'];
            
            // Cannot merge primary into itself
            if (in_array($primaryId, $duplicateIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary customer cannot be in the duplicate list'
                ], 400);
            }
            
            // Get primary customer
            $primary = CustomerModel::findOrFail($primaryId);
            
            // Cannot merge already-merged customers
            if ($primary->merged_into_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary customer is already merged into another customer'
                ], 400);
            }
            
            DB::beginTransaction();
            
            $stats = [
                'prod_orders_updated' => 0,
                'history_orders_updated' => 0,
                'customers_merged' => 0,
            ];
            
            foreach ($duplicateIds as $duplicateId) {
                $duplicate = CustomerModel::find($duplicateId);
                if (!$duplicate) continue;
                
                // Cannot merge already-merged customers
                if ($duplicate->merged_into_customer_id) {
                    continue;
                }
                
                // Update production orders
                $prodUpdated = DB::table('t_crm_prod_order')
                    ->where('customer_id', $duplicateId)
                    ->update(['customer_id' => $primaryId, 'updated_at' => now()]);
                $stats['prod_orders_updated'] += $prodUpdated;
                
                // Update history orders (if table exists)
                if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                    $historyUpdated = DB::table('t_crm_history_order')
                        ->where('customer_id', $duplicateId)
                        ->update(['customer_id' => $primaryId, 'updated_at' => now()]);
                    $stats['history_orders_updated'] += $historyUpdated;
                }

                // Move any account balance onto the surviving customer. Without
                // this the money would be stranded on a record that is hidden
                // from every screen. (Balance reads also follow the merge chain,
                // so this is belt-and-braces — but the rows should live with
                // the customer who can actually spend them.)
                if (DB::getSchemaBuilder()->hasTable('t_crm_customer_credit')) {
                    $creditMoved = (new \App\Services\CustomerCreditService())
                        ->repointOnMerge((int) $duplicateId, (int) $primaryId);
                    if ($creditMoved > 0) {
                        \Log::info('Customer credit re-pointed on merge', [
                            'from' => $duplicateId,
                            'to'   => $primaryId,
                            'rows' => $creditMoved,
                        ]);
                    }
                }

                // Mark duplicate as merged
                $duplicate->update([
                    'merged_into_customer_id' => $primaryId,
                    'merged_at' => now(),
                    'merged_by' => auth()->id(),
                    'notes' => ($duplicate->notes ? $duplicate->notes . "\n\n" : '') . 
                               "[" . now()->format('Y-m-d H:i:s') . "] Merged into customer #$primaryId by " . auth()->user()->fullname
                ]);
                
                $stats['customers_merged']++;
            }
            
            // Recalculate primary customer statistics
            $primary->recalculateStatistics();
            
            // Add merge note to primary customer
            $primary->update([
                'notes' => ($primary->notes ? $primary->notes . "\n\n" : '') . 
                           "[" . now()->format('Y-m-d H:i:s') . "] Merged " . $stats['customers_merged'] . 
                           " duplicate customer(s) into this record by " . auth()->user()->fullname
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Successfully merged {$stats['customers_merged']} customer(s). " .
                             "{$stats['prod_orders_updated']} production orders and " .
                             "{$stats['history_orders_updated']} history orders were updated.",
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer merge failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error merging customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Find potential duplicate customers (same name, different phone)
     */
    public function findDuplicates(Request $request)
    {
        try {
            // Find customers with same name but different normalized phones
            // Include combined order counts and last order date from both production and history tables
            $duplicates = DB::select("
                SELECT 
                    c1.id as customer1_id,
                    c1.first_name,
                    c1.last_name,
                    c1.phone_normalized as phone1,
                    c1.city as city1,
                    COALESCE(stats1.total_orders, 0) as orders1,
                    stats1.last_order_date as last_order1,
                    c2.id as customer2_id,
                    c2.phone_normalized as phone2,
                    c2.city as city2,
                    COALESCE(stats2.total_orders, 0) as orders2,
                    stats2.last_order_date as last_order2
                FROM t_crm_prod_customer c1
                JOIN t_crm_prod_customer c2 ON 
                    LOWER(TRIM(c1.first_name)) = LOWER(TRIM(c2.first_name))
                    AND LOWER(TRIM(c1.last_name)) = LOWER(TRIM(c2.last_name))
                    AND c1.id < c2.id
                    AND c1.phone_normalized != c2.phone_normalized
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders, MAX(last_date) as last_order_date FROM (
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats1 ON stats1.customer_id = c1.id
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders, MAX(last_date) as last_order_date FROM (
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats2 ON stats2.customer_id = c2.id
                WHERE c1.merged_into_customer_id IS NULL
                AND c2.merged_into_customer_id IS NULL
                AND c1.first_name IS NOT NULL
                AND c1.first_name != ''
                ORDER BY c1.last_name, c1.first_name
            ");
            
            return response()->json([
                'success' => true,
                'duplicates' => $duplicates,
                'count' => count($duplicates)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error finding duplicates: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Search customers for merge (from individual customer view)
     * Returns customers that can be merged into the current one
     */
    public function searchForMerge(Request $request)
    {
        try {
            $currentCustomerId = $request->get('current_customer_id');
            $search = $request->get('q', '');
            $limit = $request->get('limit', 20);
            
            if (strlen($search) < 2) {
                return response()->json([
                    'success' => true,
                    'customers' => []
                ]);
            }
            
            // Use raw query to include combined order counts from prod + history
            $customers = DB::select("
                SELECT 
                    c.id,
                    c.first_name,
                    c.last_name,
                    c.phone_normalized,
                    c.phone_original,
                    c.city,
                    COALESCE(stats.total_orders, 0) as total_orders
                FROM t_crm_prod_customer c
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders FROM (
                        SELECT customer_id, COUNT(*) as order_count 
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count 
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats ON stats.customer_id = c.id
                WHERE c.merged_into_customer_id IS NULL
                AND c.id != ?
                AND (
                    c.first_name LIKE ?
                    OR c.last_name LIKE ?
                    OR c.phone LIKE ?
                    OR c.phone_original LIKE ?
                    OR c.phone_normalized LIKE ?
                    OR c.email LIKE ?
                    OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
                )
                ORDER BY c.last_order_date DESC
                LIMIT ?
            ", [
                $currentCustomerId,
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                $limit
            ]);
            
            return response()->json([
                'success' => true,
                'customers' => collect($customers)->map(function($c) {
                    return [
                        'id' => $c->id,
                        'name' => trim($c->first_name . ' ' . $c->last_name),
                        'phone' => $c->phone_original ?: $c->phone_normalized,
                        'city' => $c->city,
                        'orders' => $c->total_orders ?: 0
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error searching customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function setVerifiedLocation(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'url' => 'nullable|string|max:500',
                // Aug-2026 — where in the UI this save was made from, so the pin
                // history can say "saved from the customer's chat pin" instead of
                // a flat "web". Purely descriptive; never changes what is saved.
                'context' => 'nullable|string|max:30',
            ]);

            // Must provide either coordinates OR URL
            if (empty($validated['latitude']) && empty($validated['longitude']) && empty($validated['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide either coordinates or a Google Maps URL'
                ], 400);
            }

            $customer = CustomerModel::findOrFail($id);

            // Verified-pin lock: this endpoint is reachable BOTH from the web
            // (session) and from the mobile app's store-mode Customers screen
            // (Sanctum bearer token) — and a rider's token could call it directly
            // too. Bind exactly the rider case: a bearer-token caller WITHOUT
            // access_store_mode may not overwrite an existing pin unless a
            // store/web unlock grant is live. Web session callers (no bearer
            // token) are never bound.
            $authUser = auth()->user();
            $isBoundRider = $request->bearerToken()
                && $authUser
                && method_exists($authUser, 'hasMobilePermission')
                && !$authUser->hasMobilePermission('access_store_mode');
            if ($isBoundRider && $customer->isVerifiedPinLocked()) {
                // Refusing a rider also raises the unlock request behind the
                // store / web banners (same as the two RiderController guards).
                $requested = \App\Services\Location\PinUnlockRequestService::raise(
                    $customer,
                    auth()->id(),
                    $request->input('order_id')
                );

                return response()->json([
                    'success' => false,
                    'message' => $requested
                        ? "This customer's location is locked. The store has been asked to unlock it — you'll be able to save as soon as they do."
                        : "This customer's location is locked. Ask the store to unlock it before changing the pin.",
                    'pin_locked' => true,
                    'unlock_requested' => $requested,
                ], 423);
            }

            // Prepare update data. A RIDER's save consumes any unlock grant so
            // the pin re-locks immediately for next time; a STORE/WEB save leaves
            // the 24h grace window open so the rider at the door can correct a
            // pin the office just set (see CustomerModel::pinGrantGrace).
            $updateData = array_merge([
                'updated_by' => auth()->id(),
                'verified_location_saved_by' => auth()->id(),
                'verified_location_saved_at' => now(),
            ], $isBoundRider
                ? CustomerModel::pinGrantConsumed()
                : CustomerModel::pinGrantGrace(
                    $customer->verified_pin_unlocked_until,
                    $customer->verified_pin_unlocked_by,
                    auth()->id()
                ));

            // Track where the coords came from so the response can warn when they
            // are approximate (geocoded) or missing (link had none, geocode failed).
            $coordsSource = null; // 'url' | 'geocoded' | 'none'

            if (!empty($validated['url'])) {
                // URL provided — store the user-supplied URL verbatim
                // AND best-effort resolve/parse it to lat/lng so the
                // customer truly counts as "verified" (the Qurbani
                // Orders page treats a customer as verified only when
                // latitude AND longitude are non-empty). Without this
                // step, customers who share a `maps.app.goo.gl/...`
                // link in chat stay flagged as "needs pin" forever.
                // Mirrors the rider endpoint's behaviour
                // (RiderController::setCustomerVerifiedLocation) so
                // both surfaces converge on the same data shape.
                $originalUrl = $validated['url'];
                $updateData['verified_location_url'] = $originalUrl;

                $resolvedUrl = $this->resolveGoogleMapsShortLink($originalUrl);
                $parsedCoords = $this->parseCoordsFromMapsUrl($resolvedUrl);
                if ($parsedCoords) {
                    $updateData['latitude'] = $parsedCoords['latitude'];
                    $updateData['longitude'] = $parsedCoords['longitude'];
                    $coordsSource = 'url';
                    \Log::info('Setting verified location from URL with extracted coords (webapp)', [
                        'customer_id' => $id,
                        'url'         => $originalUrl,
                        'resolved'    => $resolvedUrl,
                        'lat'         => $parsedCoords['latitude'],
                        'lng'         => $parsedCoords['longitude'],
                        'saved_by'    => auth()->user()->fullname,
                    ]);
                } else {
                    // Geocode-on-save fallback: the link had no coordinates (an
                    // address / ftid share link). Geocode the address so the pin
                    // isn't saved coord-less — invisible to reports + the lock
                    // (Ahmed Mujtaba / SH-21269, Jul-19).
                    $geo = \App\Services\GeocodingService::geocodeFromMapsUrl($resolvedUrl)
                        ?: \App\Services\GeocodingService::geocodeFromMapsUrl($originalUrl);
                    if ($geo) {
                        $updateData['latitude'] = $geo['latitude'];
                        $updateData['longitude'] = $geo['longitude'];
                        $coordsSource = 'geocoded';
                        \Log::info('Verified location URL had no coords — geocoded the address (approximate) (webapp)', [
                            'customer_id' => $id,
                            'resolved'    => $resolvedUrl,
                            'lat'         => $geo['latitude'],
                            'lng'         => $geo['longitude'],
                            'saved_by'    => auth()->user()->fullname,
                        ]);
                    } else {
                        $coordsSource = 'none';
                        \Log::warning('Setting verified location URL but NO coords extractable and geocode failed (webapp)', [
                            'customer_id' => $id,
                            'url'         => $originalUrl,
                            'resolved'    => $resolvedUrl,
                            'saved_by'    => auth()->user()->fullname,
                        ]);
                    }
                }
            }

            if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                // Explicit coordinates always win over URL-extracted
                // ones — they're the most accurate signal staff has.
                $updateData['latitude'] = $validated['latitude'];
                $updateData['longitude'] = $validated['longitude'];
                $coordsSource = 'url'; // an explicit dropped pin is precise
                \Log::info('Setting verified location coordinates for customer (webapp)', [
                    'customer_id' => $id,
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'saved_by' => auth()->user()->fullname,
                ]);
            }

            // Capture the pre-write pin so the change is auditable — a
            // mis-dropped pin can turn a short drive into an hour-long ETA,
            // and the old coordinates are otherwise silently overwritten.
            $oldPinLat = $customer->latitude;
            $oldPinLng = $customer->longitude;

            // Update customer
            $customer->update($updateData);

            \App\Services\AuditLogger::logCustomerPinChange(
                $customer->id,
                trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                $oldPinLat, $oldPinLng,
                $customer->latitude, $customer->longitude,
                // Say HOW the pin got here. This endpoint serves the web page, the
                // messages screen's "Set as Verified Location" button AND the
                // store app, and those are meaningfully different provenances when
                // someone is later asking "why is this pin wrong?".
                match ((string) ($validated['context'] ?? '')) {
                    'chat_pin'  => "Saved from the customer's pin in the chat",
                    'chat_link' => "Saved from the customer's map link in the chat",
                    default     => $request->bearerToken()
                        ? ($isBoundRider
                            ? 'Verified pin dropped by the rider at the door'
                            : 'Verified pin dropped in the store app')
                        : 'Verified pin set on the web app',
                }
            );

            // Pin changed → re-derive the region from it (see refreshAfterPinSave).
            \App\Services\RegionDetectionService::refreshAfterPinSave($customer->id);

            $approximate   = $coordsSource === 'geocoded';
            $coordsMissing = $coordsSource === 'none';
            $message = $coordsMissing
                ? "Saved the link, but we couldn't read an exact location from it. Please drop the pin on the map so riders and reports have precise coordinates."
                : ($approximate
                    ? 'Saved — but from the address (approximate), because the link had no exact pin. For precise delivery, drop the pin on the map.'
                    : 'Verified location saved successfully');

            return response()->json([
                'success'        => true,
                'message'        => $message,
                'coords_source'  => $coordsSource,
                'approximate'    => $approximate,
                'coords_missing' => $coordsMissing,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid location data',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to set customer verified location (webapp)', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save location: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aug-2026 — the pin's story: who set this customer's location, how, and
     * when, plus any customer-sent pin we received but did not save.
     *
     * Serves BOTH the web popover and the mobile sheet from one place (route is
     * registered in web.php and api.php), so the wording can never drift between
     * them. Read-only and defensive: if the audit table isn't on this environment
     * yet the response is a valid empty history rather than an error.
     */
    public function locationHistory(Request $request, $id)
    {
        try {
            $customer = CustomerModel::find($id);
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
            }

            $svc = app(\App\Services\Location\PinHistoryService::class);
            $history = $svc->forCustomer((int) $id, (int) $request->input('limit', 15));

            $savedByName = null;
            if ($customer->verified_location_saved_by) {
                $savedByName = ((int) $customer->verified_location_saved_by === CustomerModel::VERIFIED_PIN_CUSTOMER_ID)
                    ? 'Customer'
                    : DB::table('t_sys_user')->where('id', $customer->verified_location_saved_by)->value('fullname');
            }

            $hasPin = $customer->latitude && $customer->longitude
                && (float) $customer->latitude !== 0.0 && (float) $customer->longitude !== 0.0;

            return response()->json([
                'success'       => true,
                // false = the audit SQL hasn't been run here yet; the UI says so
                // instead of implying this customer's pin was never touched.
                'available'     => $svc->available(),
                'customer_id'   => (int) $id,
                'customer_name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                // Lets the UI deep-link to the chat (/messages?focus_phone=…) when
                // a customer-sent pin is sitting there unused.
                'phone'         => $customer->phone ?: $customer->phone_normalized,
                'current'       => [
                    'has_pin'   => (bool) $hasPin,
                    'latitude'  => $hasPin ? (float) $customer->latitude : null,
                    'longitude' => $hasPin ? (float) $customer->longitude : null,
                    'url'       => $customer->verified_location_url,
                    'maps_url'  => $hasPin
                        ? 'https://www.google.com/maps/search/?api=1&query=' . $customer->latitude . ',' . $customer->longitude
                        : null,
                    'saved_by'  => $savedByName,
                    'saved_at'  => $customer->verified_location_saved_at
                        ? \Carbon\Carbon::parse($customer->verified_location_saved_at)->toIso8601String()
                        : null,
                    // Formatted HERE, exactly like the history rows. Letting the
                    // browser format the ISO instead makes the current pin and the
                    // trail below it disagree whenever the viewer's clock is not
                    // the app's (seen in testing: 6:32 PM rendered as 4:32 PM).
                    'saved_at_display' => $customer->verified_location_saved_at
                        ? \Carbon\Carbon::parse($customer->verified_location_saved_at)->format('d M Y, g:i A')
                        : null,
                ],
                'history'       => $history,
                'pending_reply' => $svc->pendingReply((int) $id),
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to load customer location history', [
                'customer_id' => $id,
                'error'       => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Could not load location history',
            ], 500);
        }
    }

    /**
     * Aug-2026 — every customer currently sitting on a location pin we received
     * but did not save. Small by construction (the flag ages out at 30 days and
     * resolved ones drop off), so the orders page can badge its rows from one
     * cheap call instead of every order-list payload carrying the flag.
     */
    public function pinRepliesPending()
    {
        try {
            $map = app(\App\Services\Location\PinHistoryService::class)->allPending();
            return response()->json([
                'success' => true,
                'pending' => (object) $map, // object, so JS can index by customer id
                'count'   => count($map),
            ]);
        } catch (\Exception $e) {
            // Never let a badge break the orders page.
            return response()->json(['success' => true, 'pending' => (object) [], 'count' => 0]);
        }
    }

    /**
     * Verified-pin lock (Jul-2026): grant a time-boxed unlock so a RIDER can
     * change this customer's locked pin. Web-session surface of the same grant
     * the store-mode API endpoint issues (RiderController::unlockCustomerVerifiedPin).
     * Store-level trust: any authenticated web user may grant (owner ruling).
     */
    public function unlockVerifiedPin(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            $until = now()->addMinutes(CustomerModel::VERIFIED_PIN_UNLOCK_MINUTES);
            // Answers any standing rider request → its banner leaves every screen.
            $customer->update(array_merge([
                'verified_pin_unlocked_until' => $until,
                'verified_pin_unlocked_by'    => auth()->id(),
            ], CustomerModel::pinUnlockRequestCleared()));
            \Log::info('Verified pin unlocked for rider edit (webapp)', [
                'customer_id' => $customer->id,
                'unlocked_by' => auth()->user()->fullname ?? auth()->id(),
                'until'       => $until->toDateTimeString(),
            ]);
            return response()->json([
                'success'        => true,
                'message'        => 'Location unlocked — the rider can update the pin now.',
                'unlocked_until' => $until->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to unlock verified pin (webapp)', ['customer_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to unlock location'], 500);
        }
    }

    /**
     * Cancel a live unlock grant (mis-click / no longer needed). The pin simply
     * returns to its default locked-for-riders state.
     */
    public function relockVerifiedPin(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            // Re-locking also settles a standing request — deciding NOT to
            // unlock is still a decision, and leaving the banner up would keep
            // asking for something already answered.
            $customer->update(array_merge([
                'verified_pin_unlocked_until' => null,
                'verified_pin_unlocked_by'    => null,
            ], CustomerModel::pinUnlockRequestCleared()));
            \Log::info('Verified pin unlock cancelled (webapp)', [
                'customer_id' => $customer->id,
                'by'          => auth()->user()->fullname ?? auth()->id(),
            ]);
            return response()->json(['success' => true, 'message' => 'Location locked again.']);
        } catch (\Exception $e) {
            \Log::error('Failed to re-lock verified pin (webapp)', ['customer_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to re-lock location'], 500);
        }
    }

    /**
     * Aug-2026 — dismiss a rider's unlock request WITHOUT unlocking.
     *
     * The banner's ✕: "seen it, handled another way" (or the rider asked by
     * mistake). The pin stays locked; only the request goes. Without this the
     * only way to clear a request you don't want to grant would be to grant it.
     */
    public function dismissPinUnlockRequest(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            \App\Services\Location\PinUnlockRequestService::dismiss($customer);
            \Log::info('Verified-pin unlock request dismissed (webapp)', [
                'customer_id' => $customer->id,
                'by'          => auth()->user()->fullname ?? auth()->id(),
            ]);
            return response()->json(['success' => true, 'message' => 'Request dismissed.']);
        } catch (\Exception $e) {
            \Log::error('Failed to dismiss pin unlock request', ['customer_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to dismiss request'], 500);
        }
    }

    /**
     * Follow a shortened Google Maps URL to its final destination so
     * we can extract coordinates. Returns the original URL unchanged
     * if it isn't shortened or if the redirect lookup fails. Used by
     * setVerifiedLocation() — duplicated (deliberately) from the
     * rider controller's helper of the same shape rather than shared
     * via a trait, because both controllers are independently
     * deployable and we don't want a shared dependency churn here.
     */
    private function resolveGoogleMapsShortLink(string $url): string
    {
        $shortDomains = ['goo.gl', 'maps.app.goo.gl', 'g.co'];
        $needsResolve = false;
        foreach ($shortDomains as $d) {
            if (stripos($url, $d) !== false) { $needsResolve = true; break; }
        }
        if (!$needsResolve || !function_exists('curl_init')) {
            return $url;
        }
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NizamiFarms-LocResolver/1.0)');
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400 && !empty($finalUrl)) {
                return (string) $finalUrl;
            }
        } catch (\Throwable $e) {
            \Log::debug('CustomerController: resolveGoogleMapsShortLink failed (non-fatal)', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
        }
        return $url;
    }

    /**
     * Extract lat/lng from a resolved Google Maps URL. Recognises:
     *   - ?q=LAT,LNG / ?ll=LAT,LNG / ?destination=LAT,LNG
     *   - /@LAT,LNG,...  (the canonical share format)
     *   - /place/LAT,LNG/...
     * Returns ['latitude' => float, 'longitude' => float] or null if
     * the URL contains no coordinates (e.g. it's a named-place share
     * that only resolves to a Place ID without coords in the URL).
     */
    private function parseCoordsFromMapsUrl(?string $url): ?array
    {
        if (empty($url)) { return null; }
        if (preg_match('/[?&](?:q|ll|destination)=(-?\d+\.\d+),(-?\d+\.\d+)/i', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        if (preg_match('~/place/(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        return null;
    }

    public function destroy($id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // Check if customer has orders
            if ($customer->total_orders > 0) {
                return redirect()->back()->with('error', 'Cannot delete customer with existing orders. Please deactivate instead.');
            }
            
            $customer->delete();
            
            return redirect()->route('customers.index')->with('success', 'Customer deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error deleting customer: ' . $e->getMessage());
        }
    }
    
    /**
     * Geocode a single customer's address
     */
    public function geocode(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            if (!$customer->address1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer has no address to geocode'
                ], 400);
            }
            
            // Force update even if coordinates exist
            $forceUpdate = $request->boolean('force', false);
            
            if (!$forceUpdate && $customer->latitude && $customer->longitude) {
                return response()->json([
                    'success' => true,
                    'message' => 'Customer already has coordinates',
                    'coordinates' => [
                        'latitude' => $customer->latitude,
                        'longitude' => $customer->longitude,
                    ]
                ]);
            }
            
            $result = \App\Services\GeocodingService::geocodeCustomer($id, $forceUpdate, [
                'trigger' => 'customer_api', 'user_id' => auth()->id(),
            ]);
            
            if ($result) {
                $customer->refresh();
                return response()->json([
                    'success' => true,
                    'message' => 'Address geocoded successfully',
                    'coordinates' => [
                        'latitude' => $customer->latitude,
                        'longitude' => $customer->longitude,
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not geocode address. The address may be too vague or not found.'
                ], 400);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Geocoding failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Batch geocode customers without coordinates
     */
    public function batchGeocode(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);
            $limit = min($limit, 100); // Cap at 100 to avoid timeout
            
            $result = \App\Services\GeocodingService::batchGeocodeCustomers($limit);
            
            return response()->json([
                'success' => true,
                'message' => "Geocoded {$result['success']} of {$result['total']} customers",
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Batch geocoding failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Geocode a single customer by ID
     * Used for on-demand geocoding from the map views
     */
    public function geocodeSingle(Request $request, $customerId)
    {
        try {
            $forceUpdate = $request->get('force', false);
            
            $customer = \DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->first(['id', 'first_name', 'last_name', 'address1', 'city', 'geocoded_latitude', 'geocoded_longitude']);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }
            
            // Skip if already has geocoded coordinates (unless force update)
            if (!$forceUpdate && $customer->geocoded_latitude && $customer->geocoded_longitude) {
                return response()->json([
                    'success' => true,
                    'already_geocoded' => true,
                    'location' => [
                        'latitude' => (float) $customer->geocoded_latitude,
                        'longitude' => (float) $customer->geocoded_longitude,
                    ],
                    'message' => 'Already geocoded'
                ]);
            }
            
            // Perform geocoding
            $result = \App\Services\GeocodingService::geocodeCustomer($customerId, $forceUpdate, [
                'trigger' => 'customer_api', 'user_id' => auth()->id(),
            ]);
            
            if ($result) {
                // Fetch updated coordinates
                $updated = \DB::table('t_crm_prod_customer')
                    ->where('id', $customerId)
                    ->first(['geocoded_latitude', 'geocoded_longitude']);
                
                return response()->json([
                    'success' => true,
                    'geocoded' => true,
                    'location' => [
                        'latitude' => (float) $updated->geocoded_latitude,
                        'longitude' => (float) $updated->geocoded_longitude,
                    ],
                    'customer_name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'message' => 'Successfully geocoded'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'geocoded' => false,
                    'message' => 'Could not geocode address: ' . ($customer->address1 ?? 'No address')
                ]);
            }
            
        } catch (\Exception $e) {
            \Log::error('Failed to geocode customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to geocode: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get geocoding statistics
     */
    public function geocodeStats()
    {
        try {
            $total = CustomerModel::count();
            
            // Verified locations (manually set by rider - high accuracy)
            $withVerified = CustomerModel::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->count();
            
            // Geocoded locations (auto-generated from address - approximate)
            $withGeocoded = CustomerModel::whereNotNull('geocoded_latitude')
                ->whereNotNull('geocoded_longitude')
                ->count();
            
            // Either verified or geocoded
            $withAnyCoords = CustomerModel::where(function($q) {
                $q->where(function($q2) {
                    $q2->whereNotNull('latitude')->whereNotNull('longitude');
                })->orWhere(function($q2) {
                    $q2->whereNotNull('geocoded_latitude')->whereNotNull('geocoded_longitude');
                });
            })->count();
            
            $withAddress = CustomerModel::whereNotNull('address1')
                ->where('address1', '!=', '')
                ->count();
            
            // Needs geocoding: has address but no geocoded coordinates
            $needsGeocode = CustomerModel::whereNull('geocoded_latitude')
                ->whereNotNull('address1')
                ->where('address1', '!=', '')
                ->count();
            
            return response()->json([
                'success' => true,
                'stats' => [
                    'total_customers' => $total,
                    'with_verified_location' => $withVerified,
                    'with_geocoded_location' => $withGeocoded,
                    'with_any_coordinates' => $withAnyCoords,
                    'with_address' => $withAddress,
                    'needs_geocoding' => $needsGeocode,
                    'coverage_percent' => $withAddress > 0 ? round(($withAnyCoords / $withAddress) * 100, 1) : 0,
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get stats: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Upload a promo image for WhatsApp messaging
     * Stores image in public storage and returns the URL
     */
    public function uploadPromoImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
            ]);
            
            $file = $request->file('image');
            $filename = 'whatsapp_promo_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('whatsapp_promo', $filename, 'public');
            
            $url = asset('storage/' . $path);
            $url = str_replace('://app.nizamifarms.com', '://www.nizamifarms.com', $url);
            
            return response()->json([
                'success' => true,
                'url' => $url,
                'path' => $path
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid image: ' . implode(', ', $e->validator->errors()->all())
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete a promo image from storage
     */
    public function deletePromoImage(Request $request)
    {
        try {
            $path = $request->input('path');
            
            // Security: Only allow deleting from whatsapp_promo directory
            if (!$path || !str_starts_with($path, 'whatsapp_promo/')) {
                return response()->json(['success' => false, 'message' => 'Invalid path'], 400);
            }
            
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete image: ' . $e->getMessage()
            ], 500);
        }
    }
}
