<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Validator;
use App\Services\ShopifyService;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Request\RequestCategoryModel;
class OrderController extends Controller
{


    protected $orderModel;
    protected ShopifyService $shopify;
    protected WooCommerceService $wooCommerce;
    public function __construct(OrderModel  $orderModel, ShopifyService $shopify, WooCommerceService $wooCommerce)
    {
        $this->orderModel = $orderModel;
        $this->shopify = $shopify;
        $this->wooCommerce = $wooCommerce;
    }

    /**
     * NF: Exclude Qurbani orders from an Eloquent OrderModel query.
     * Mirrors the list-side filter in index() (see "Exclude qurbani orders from regular open orders view"),
     * so tab badges, "All Open" card and verified/unverified sub-counts stay in sync with the rows shown.
     * An order is considered qurbani if it has qurbani_day set, OR any of its line items uses a product
     * whose attribute_1 = 'qurbani' (case-insensitive).
     */
    private function applyNonQurbaniFilter($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('qurbani_day')
              ->whereDoesntHave('lineItems', function ($li) {
                  $li->whereHas('product', function ($p) {
                      $p->whereRaw("LOWER(attribute_1) = 'qurbani'");
                  });
              });
        });
    }

    /**
     * NF: Same non-qurbani filter, expressed for a raw Query Builder on t_crm_prod_order
     * (because the status-counts endpoint uses DB::table instead of Eloquent).
     * $orderAlias is the alias used for t_crm_prod_order in the outer query.
     */
    private function applyNonQurbaniFilterDb($query, string $orderAlias = 'o')
    {
        return $query->whereNull($orderAlias . '.qurbani_day')
            ->whereNotExists(function ($sub) use ($orderAlias) {
                $sub->select(\DB::raw(1))
                    ->from('t_crm_prod_order_line_item as li')
                    ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                    ->whereColumn('li.order_id', $orderAlias . '.id')
                    ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
            });
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $source = $request->get('source', 'other'); // 'other' shows non-shopify from prod_order
        // Default to 'open' (Open Orders) for non-shopify, 'all' for shopify
        $defaultTab = $source === 'shopify' ? 'all' : 'open';
        $tab = $request->get('tab', $defaultTab); // 'all', 'approvals', 'open', or 'riders'
        $status = $request->get('status', ''); // Status filter
        $date = $request->get('date', ''); // Order date filter
        $deliveryDate = $request->get('delivery_date', ''); // Delivery date filter (from status history)
        $orderMonth = $request->get('order_month', ''); // Order month filter (YYYY-MM)
        $deliveryMonth = $request->get('delivery_month', ''); // Delivery month filter (YYYY-MM)

        // Check permissions for Shopify orders
        $canViewShopify = $user->hasPermission('view_shopify_orders');
        $canViewAllOrders = $user->hasPermission('view_all_orders');

        // RETURNS (Sep-2026) — may this user take a delivered order back?
        // Asked through the service so the web page, the API and the status
        // endpoint all answer the identical question (permission, then the
        // config-email / taimur|shabib fallback that identifies Shabib, who
        // shares the Management role with others).
        $canReturnOrders = app(\App\Services\CRM\OrderReturnService::class)->userCanReturn($user);

        // If trying to view Shopify but don't have permission, redirect to main orders
        if ($source === 'shopify' && !$canViewShopify) {
            return redirect()->route('orders.index', ['source' => 'other'])
                ->with('error', 'You do not have permission to view Shopify orders.');
        }

        // Build query per source
        if ($source === 'shopify') {
            // Read from new Shopify tables
            $query = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems']);
            
            // If specifically viewing approvals tab, filter to unconverted only
            if ($tab === 'approvals') {
                $query->where(function($q){
                    $q->whereNull('converted')->orWhere('converted', 0);
                });
            }
            // Otherwise show ALL Shopify orders
        } else {
            // Non-shopify from prod orders
            $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems', 'assignedRider', 'currentStatusHistory'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                });
            
            // Detect if request is from mobile API (rider mode)
            $isMobileRequest = $request->is('api/rider/*');
            
            // Permission-based filtering:
            // - Mobile requests (rider mode): ALWAYS filter to assigned orders only, even for admins
            // - Web requests: users without view_all_orders see only their assigned orders
            if ($isMobileRequest || !$canViewAllOrders) {
                $query->where('assigned_rider_user_id', auth()->id());
            }
            
            // If viewing open orders or riders tab, filter to exclude completed statuses
            if ($tab === 'open' || $tab === 'riders') {
                $query->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
                // Exclude qurbani orders from regular open orders view
                $query->where(function ($q) {
                    $q->whereNull('qurbani_day')
                      ->whereDoesntHave('lineItems', function ($li) {
                          $li->whereHas('product', function ($p) {
                              $p->whereRaw("LOWER(attribute_1) = 'qurbani'");
                          });
                      });
                });
            }
        }
        
        // Apply status filter if provided
        if (!empty($status)) {
            $query->where('order_status', $status);
        }
        
        // Apply order date filter if provided
        if (!empty($date)) {
            $query->whereDate('order_date', $date);
        }

        // Apply delivery date filter (non-Shopify orders only) using status history 'delivered' changed_at
        if ($source !== 'shopify' && !empty($deliveryDate)) {
            $query->whereExists(function($q) use ($deliveryDate) {
                $q->select(\DB::raw(1))
                  ->from('t_crm_order_status_history as h')
                  ->whereColumn('h.order_id', 't_crm_prod_order.id')
                  ->where('h.status_code', 'delivered')
                  ->whereDate('h.changed_at', $deliveryDate);
            });
        }
        
        // Apply order month filter if provided (YYYY-MM format)
        if (!empty($orderMonth)) {
            $query->whereRaw("DATE_FORMAT(order_date, '%Y-%m') = ?", [$orderMonth]);
        }
        
        // Apply delivery month filter (non-Shopify orders only) using status history
        if ($source !== 'shopify' && !empty($deliveryMonth)) {
            $query->whereExists(function($q) use ($deliveryMonth) {
                $q->select(\DB::raw(1))
                  ->from('t_crm_order_status_history as h')
                  ->whereColumn('h.order_id', 't_crm_prod_order.id')
                  ->where('h.status_code', 'delivered')
                  ->whereRaw("DATE_FORMAT(h.changed_at, '%Y-%m') = ?", [$deliveryMonth]);
            });
        }
        
        // Handle per_page parameter
        $perPage = $request->get('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25; // Validate per_page values
        
        $orders = $query->orderBy('order_date', 'desc')->paginate($perPage);
        
        // Append all parameters to pagination links so they're preserved
        $appendParams = ['source' => $source, 'per_page' => $perPage, 'tab' => $tab];
        if (!empty($status)) $appendParams['status'] = $status;
        if (!empty($date)) $appendParams['date'] = $date;
        if (!empty($deliveryDate)) $appendParams['delivery_date'] = $deliveryDate;
        if (!empty($orderMonth)) $appendParams['order_month'] = $orderMonth;
        if (!empty($deliveryMonth)) $appendParams['delivery_month'] = $deliveryMonth;
        $orders->appends($appendParams);
        
        // Counts for badges
        if ($source === 'shopify') {
            // For Shopify page: count all orders and approvals separately
            $shopifyCount = \App\Models\CRM\ShopifyOrderModel::count(); // All Shopify orders
            $approvalsCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count(); // Only unconverted
            $otherCount = 0; // Not relevant for Shopify page
            $openCount = 0; // Not relevant for Shopify page
        } else {
            // For main Invoices page: count as before
            // Only show Shopify count if user has permission
            $shopifyCount = $canViewShopify ? \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count() : 0;
            $approvalsCount = 0; // Not relevant for main page
            
            // If user can't view all orders, count only their assigned orders
            $otherCountQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            });
            if (!$canViewAllOrders) {
                $otherCountQuery->where('assigned_rider_user_id', auth()->id());
            }
            $otherCount = $otherCountQuery->count();
            
            $openCountQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
            if (!$canViewAllOrders) {
                $openCountQuery->where('assigned_rider_user_id', auth()->id());
            }
            // NF: keep badge in sync with the Open Orders list (which already hides qurbani).
            $this->applyNonQurbaniFilter($openCountQuery);
            $openCount = $openCountQuery->count();
        }

        // Jun-2026 — Payment-proof status badges (WhatsApp screenshot / bank
        // email) for the current page of orders. Bulk lookup, no N+1. Empty
        // map when the feature is off.
        $paymentProofMap = [];
        if (config('payment_signals.enabled')) {
            $orderIds = collect($orders->items())->pluck('id')->filter()->values()->all();
            if (!empty($orderIds)) {
                $paymentProofMap = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                    ->forOrders($orderIds);
            }
        }

        // Jul-2026 — Invoice-sent tick for the INITIAL server-rendered page. filter()
        // computes the same flags for AJAX refetches; without this, a full page load /
        // hard refresh painted plain WhatsApp buttons until the first in-page refetch.
        // Same non-fatal guard — on any error the ticks simply don't show, the page
        // never breaks.
        try {
            $pageOrders = collect($orders->items());
            $invNums = $pageOrders->pluck('order_number')->filter()->unique()->values()->all();
            if (!empty($invNums)) {
                $sentMap = self::invoiceSendStatusMap($invNums);
                foreach ($pageOrders as $o) {
                    $n = $o->order_number ?? '';
                    $st = ($n !== '') ? ($sentMap[$n] ?? null) : null;
                    $o->invoice_sent = (bool) ($st['sent_at'] ?? null);
                    $o->invoice_sent_at = $st['sent_at'] ?? null;
                    $o->invoice_send_failed = (bool) ($st['failed'] ?? false);
                    $o->invoice_send_error = $st['error'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            // non-fatal — ticks just don't show on first paint
        }

        // Jul-2026 — "this automation is running" strips for the Open Orders tab.
        // SEASONAL WhatsApp automations (the monsoon storage guidelines) are easy
        // to switch on and then forget for months, so every rule that declares
        // `orders_banner` in the registry announces itself here while it's live.
        // Rendered server-side (no extra request); dismissing is per-day and
        // client-side. Non-fatal — on any error the page simply shows no strip.
        $automationBanners = [];
        try {
            $automationBanners = $this->liveAutomationBanners($user);
        } catch (\Throwable $e) {
            \Log::warning('Orders page: automation banner lookup failed (non-fatal)', [
                'error' => $e->getMessage(),
            ]);
        }

        return view('pages.orders.index', compact('orders', 'source', 'tab', 'shopifyCount', 'approvalsCount', 'otherCount', 'openCount', 'canViewShopify', 'canViewAllOrders', 'canReturnOrders', 'user', 'paymentProofMap', 'automationBanners'));
    }

    /**
     * The currently-LIVE automations that want a strip on the Open Orders page.
     *
     * "Live" is the fully-resolved state (master switch ON + rule enabled +
     * rule wired), i.e. exactly the condition under which messages actually go
     * out — so the strip can never claim something is sending when it isn't.
     *
     * `can_manage` decides whether the strip offers a Turn-off button: the
     * toggle endpoint is gated on manage_wa_auto_reply, so users without it get
     * the notice and the dismiss only.
     */
    protected function liveAutomationBanners($user): array
    {
        $rules = \App\Services\WhatsApp\Automation\AutomationRegistry::withOrdersBanner();
        if (empty($rules)) {
            return [];
        }

        $service = app(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class);
        $canManage = $user
            && method_exists($user, 'hasMobilePermission')
            && $user->hasMobilePermission('manage_wa_auto_reply');

        $out = [];
        foreach ($rules as $desc) {
            if (!$service->isRuleLive($desc['key'])) {
                continue;
            }
            $out[] = [
                'key'        => $desc['key'],
                'icon'       => $desc['orders_banner']['icon'] ?? '🤖',
                'title'      => $desc['orders_banner']['title'] ?? $desc['label'],
                'can_manage' => (bool) $canManage,
            ];
        }
        return $out;
    }

    /**
     * Bulk invoice-send status for a set of order numbers (orders-page ticks).
     *
     * Returns order_number => [
     *   'sent_at' => latest successful (non-failed) invoice-template send time, or null
     *   'failed'  => true when the LATEST invoice-send attempt has webhook status
     *                'failed' — a resend that failed flips the order back to red
     *                even if an older send once succeeded (latest attempt wins)
     *   'error'   => error text of that latest failed attempt (tooltip), or null
     * ]
     *
     * One indexed bulk query (idx_wa_msg_related_order) plus one small follow-up
     * only for orders whose latest attempt failed. Orders with no invoice sends
     * have no entry. Callers wrap in try/catch — on error, ticks just don't show.
     */
    private static function invoiceSendStatusMap(array $orderNumbers): array
    {
        if (empty($orderNumbers)) {
            return [];
        }

        $rows = \App\Models\WhatsApp\MessageModel::query()
            ->whereIn('related_order_number', $orderNumbers)
            ->where('direction', 'outbound')
            ->where('content', 'LIKE', 'Invoice #%')
            ->selectRaw("related_order_number,
                MAX(CASE WHEN status != 'failed' THEN created_at END) as sent_at,
                MAX(created_at) as last_at")
            ->groupBy('related_order_number')
            ->get();

        $map = [];
        $failedNums = [];
        foreach ($rows as $r) {
            // Datetime strings compare correctly lexicographically. Latest
            // attempt failed ⇔ no success exists at all, or something newer
            // than the newest success exists (and it can only be a failure).
            $failed = ($r->sent_at === null)
                || ($r->last_at !== null && $r->last_at > $r->sent_at);
            $map[$r->related_order_number] = [
                'sent_at' => $r->sent_at,
                'failed' => $failed,
                'error' => null,
            ];
            if ($failed) {
                $failedNums[] = $r->related_order_number;
            }
        }

        if (!empty($failedNums)) {
            // Ascending order → later rows overwrite, leaving each order with
            // its LATEST failed attempt's error text.
            \App\Models\WhatsApp\MessageModel::query()
                ->whereIn('related_order_number', $failedNums)
                ->where('direction', 'outbound')
                ->where('content', 'LIKE', 'Invoice #%')
                ->where('status', 'failed')
                ->orderBy('created_at')
                ->get(['related_order_number', 'error_code', 'error_message'])
                ->each(function ($m) use (&$map) {
                    $err = trim(($m->error_message ?: '') . ($m->error_code ? " (code {$m->error_code})" : ''));
                    $map[$m->related_order_number]['error'] = $err !== '' ? $err : 'delivery failed';
                });
        }

        return $map;
    }

    /**
     * Lightweight count of pending Shopify approvals (unconverted staging rows).
     * Jul-2026: powers the Approvals right-drawer's live badge — a cheap indexed
     * COUNT so the 30s poll costs almost nothing (vs. fetching the full order
     * list just for a number, which is what the mobile badge does today).
     * Same count + same permission as the "Order Approvals" tab badge.
     */
    public function approvalsCount(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->hasPermission('view_shopify_orders')) {
            return response()->json(['count' => 0]);
        }
        $count = \App\Models\CRM\ShopifyOrderModel::where(function ($q) {
            $q->whereNull('converted')->orWhere('converted', 0);
        })->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Aug-2026 — riders waiting on a locked verified pin (30s poll).
     *
     * Powers the orders-page banner. Its whole job is to be a LIVE answer: the
     * request disappears from this list the moment anyone unlocks, saves the
     * pin, re-locks or dismisses it, so the banner can never sit there asking
     * for something already done. See PinUnlockRequestService for why that is
     * true by construction rather than by remembering to clear an alert.
     */
    public function pinUnlockRequests(Request $request)
    {
        if (!auth()->check()) {
            return response()->json(['success' => true, 'requests' => []]);
        }

        return response()->json([
            'success'  => true,
            'requests' => \App\Services\Location\PinUnlockRequestService::live(),
        ]);
    }

    public function show($id)
    {
        try {
            $order = $this->findOrder($id);
            
            // Attach discounts to the order object for frontend convenience
            $order->discounts = $order->discounts ?? [];

            // Enh-2: resolve who completed the dispatch hand-over scan so the detail modal can show
            // an audit line ("scanned by X"). Null when not scanned or on staging orders (no column).
            $order->dispatch_scanned_by_name = $order->dispatch_scanned_by
                ? (\DB::table('t_sys_user')->where('id', $order->dispatch_scanned_by)->value('fullname') ?: null)
                : null;
            // Same for the rider's delivery-scan audit stamp (proof he scanned at delivery + when).
            $order->delivery_scanned_by_name = $order->delivery_scanned_by
                ? (\DB::table('t_sys_user')->where('id', $order->delivery_scanned_by)->value('fullname') ?: null)
                : null;
            // Aug-2026: the rider's scan now records WHICH packet indices he scanned (it used to keep
            // only the time), and a short delivery names the manager who approved it. Both are null /
            // 0 on staging orders and before the migration runs — the modal just omits those lines.
            $order->delivery_scanned_count = $order->delivery_scanned_packets
                ? count((array) json_decode($order->delivery_scanned_packets, true))
                : 0;
            $order->scan_bypass_decided_by_name = $order->scan_bypass_decided_by
                ? (\DB::table('t_sys_user')->where('id', $order->scan_bypass_decided_by)->value('fullname') ?: null)
                : null;

            // Get delivery location if order is delivered
            $deliveryLocation = null;
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                $deliveryHistory = \DB::table('t_crm_order_status_history')
                    ->where('order_id', $order->id)
                    ->where('status_code', 'delivered')
                    ->where('is_current', 1)
                    ->select('delivery_latitude', 'delivery_longitude', 'changed_at')
                    ->first();
                
                if ($deliveryHistory && $deliveryHistory->delivery_latitude && $deliveryHistory->delivery_longitude) {
                    $deliveryLocation = [
                        'latitude' => $deliveryHistory->delivery_latitude,
                        'longitude' => $deliveryHistory->delivery_longitude,
                        'delivered_at' => $deliveryHistory->changed_at,
                        'google_maps_url' => "https://www.google.com/maps?q={$deliveryHistory->delivery_latitude},{$deliveryHistory->delivery_longitude}"
                    ];
                }
            }
            
            // Get verified location from customer
            $verifiedLocation = null;
            if ($order->customer) {
                if ($order->customer->verified_location_url || ($order->customer->latitude && $order->customer->longitude)) {
                    $verifiedLocation = [
                        'latitude' => $order->customer->latitude,
                        'longitude' => $order->customer->longitude,
                        'url' => $order->customer->verified_location_url,
                        'google_maps_url' => $order->customer->verified_location_url ?: 
                            ($order->customer->latitude && $order->customer->longitude ? 
                                "https://www.google.com/maps?q={$order->customer->latitude},{$order->customer->longitude}" : null),
                        'saved_by' => \App\Models\CRM\CustomerModel::verifierLabel($order->customer->verified_location_saved_by),
                        'saved_at' => $order->customer->verified_location_saved_at,
                        // Verified-pin lock state for the 🔒/🔓 chip + unlock button
                        'pin_locked' => $order->customer->isVerifiedPinLocked(),
                        'unlock_active' => $order->customer->verifiedPinUnlockActive(),
                        'unlocked_until' => $order->customer->verifiedPinUnlockActive()
                            ? $order->customer->verified_pin_unlocked_until->toIso8601String() : null,
                        // Aug-2026 — is a pin the CUSTOMER sent sitting unused in
                        // the chat? The auto-save never overwrites an existing pin
                        // (correctly), but until now that refusal was invisible and
                        // an order shipped to a stale address the next day. One
                        // indexed lookup, detail view only — never on a list poll.
                        'pin_reply_pending' => app(\App\Services\Location\PinHistoryService::class)
                            ->pendingReply((int) $order->customer->id),
                    ];
                }
            }

            // ⭐ Get pending approval status if order has a ledger entry
            $pendingApproval = null;
            if ($order->ledger_transaction_id) {
                $ledger = \App\Models\FIN\LedgerModel::select('id', 'approval_status', 'created_at')
                    ->find($order->ledger_transaction_id);
                
                if ($ledger && in_array($ledger->approval_status, [
                    \App\Models\FIN\LedgerModel::STATUS_PENDING,
                    \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
                    \App\Models\FIN\LedgerModel::STATUS_PENDING_L2
                ])) {
                    $levelLabel = match($ledger->approval_status) {
                        \App\Models\FIN\LedgerModel::STATUS_PENDING_L2 => 'L2',
                        default => 'L1'
                    };
                    $pendingApproval = [
                        'ledger_id' => $ledger->id,
                        'status' => $ledger->approval_status,
                        'level' => $levelLabel,
                        'message' => "Pending {$levelLabel} Approval",
                        'created_at' => $ledger->created_at ? $ledger->created_at->format('M j, Y') : null,
                        'view_url' => route('fin.ledger.show', ['id' => $ledger->id, 'origin' => 'orders'])
                    ];
                }
            }
            
            $regionName = null;
            $custRegionId = $order->customer->delivery_region_id ?? null;
            if ($custRegionId) {
                $regionName = \DB::table('t_ops_delivery_region')
                    ->where('id', $custRegionId)
                    ->value('name');
            }

            $isQurbani = !empty($order->qurbani_day) || !empty($order->qurbani_slot) || !empty($order->qurbani_region) || !empty($order->qurbani_delivery_type);
            // Also check line items for qurbani fields or qurbani products
            if (!$isQurbani) {
                $isQurbani = $order->lineItems->contains(function($li) {
                    return !empty($li->qurbani_day) || !empty($li->qurbani_slot) || !empty($li->qurbani_region) || !empty($li->qurbani_delivery_type);
                });
            }
            if (!$isQurbani) {
                $isQurbani = $order->hasQurbaniItems();
            }
            $qurbaniPayments = [];
            if ($isQurbani) {
                // Join in the receiving bank so the UI can display "HBL",
                // "Meezan", etc. on the payment history cards without
                // another round-trip. Left join keeps legacy payments
                // (no receiving bank captured) visible.
                $qurbaniPayments = \DB::table('t_crm_order_payments as p')
                    ->leftJoin('t_sys_user as u', 'p.created_by', '=', 'u.id')
                    ->leftJoin('t_fin_ledger as l', 'p.ledger_transaction_id', '=', 'l.id')
                    ->leftJoin('t_fin_online_receiving_accounts as b', 'p.receiving_account_id', '=', 'b.id')
                    ->where('p.order_id', $order->id)
                    ->where('p.status', 'active')
                    ->orderBy('p.payment_date', 'desc')
                    ->select([
                        'p.id', 'p.amount', 'p.payment_method', 'p.payment_date',
                        'p.reference', 'p.notes', 'p.created_by', 'p.created_at',
                        'p.receiving_account_id',
                        'u.fullname as created_by_name',
                        'b.name as receiving_account_name',
                        'b.short_code as receiving_account_code',
                        'b.color_hex as receiving_account_color',
                        'l.approval_status as ledger_approval_status',
                        'l.settlement_status as ledger_settlement_status',
                    ])
                    ->get();
            }

            // Who last set each line-item quantity (barcode-qty badge: source +
            // when + who). Only PRODUCTION line items define this relation —
            // Shopify staging line items (ShopifyOrderLineItemModel) do not, so
            // eager-loading it on a staging order throws RelationNotFoundException,
            // which the catch below would mask as a misleading "Order not found"
            // (this is exactly what broke the Shopify approval detail view).
            if (!($order instanceof \App\Models\CRM\ShopifyOrderModel)) {
                $order->load('lineItems.qtyUpdater:id,fullname');
            }

            // Jun-2026: the customer's latest WhatsApp button reply (Confirm /
            // Split / Cancel) so the approve/detail modal can show exactly what
            // they chose. Null when there's none. Never throws.
            $customerReply = null;
            // Prefer the reply linked to THIS exact order (button tap → context.id),
            // fall back to the customer's latest button reply.
            if (!empty($order->order_number)) {
                $orderMap = \App\Services\WhatsApp\OrderReplyService::latestReplyForOrders([$order->order_number], 90);
                $customerReply = $orderMap[$order->order_number] ?? null;
            }
            if (!$customerReply && $order->customer_id) {
                $replyMap = \App\Services\WhatsApp\OrderReplyService::latestReplyForCustomers([$order->customer_id], 60);
                $customerReply = $replyMap[$order->customer_id] ?? null;
            }

            return response()->json([
                'success' => true,
                'order' => $order,
                'lineItems' => $order->lineItems,
                'discounts' => $order->discounts,
                'delivery_location' => $deliveryLocation,
                'verified_location' => $verifiedLocation,
                'customer_notes' => $order->customer ? ($order->customer->notes ?? null) : null,
                'has_customer_notes' => $order->customer && !empty($order->customer->notes),
                'order_note' => $order->note ?? null,
                'has_order_note' => !empty($order->note),
                'pending_approval' => $pendingApproval,
                'has_pending_approval' => $pendingApproval !== null,
                'delivery_region_name' => $regionName,
                'delivery_region_id' => $custRegionId,
                'is_qurbani' => $isQurbani,
                'qurbani_payments' => $qurbaniPayments,
                'customer_reply' => $customerReply,
            ]);
        } catch (\Exception $e) {
            // Log the real cause — this generic "Order not found" has historically
            // masked unrelated exceptions (e.g. eager-loading a relation that a
            // Shopify staging order lacks), making such bugs hard to diagnose.
            \Log::error('OrderController::show failed', [
                'order_id' => $id,
                'source'   => request()->query('source'),
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    public function getQurbaniPayments($id)
    {
        try {
            $order = $this->findOrder($id);
            $payments = \DB::table('t_crm_order_payments as p')
                ->leftJoin('t_sys_user as u', 'p.created_by', '=', 'u.id')
                ->leftJoin('t_fin_ledger as l', 'p.ledger_transaction_id', '=', 'l.id')
                ->leftJoin('t_fin_online_receiving_accounts as b', 'p.receiving_account_id', '=', 'b.id')
                ->where('p.order_id', $id)
                ->where('p.status', 'active')
                ->orderBy('p.payment_date', 'desc')
                ->select([
                    'p.id', 'p.amount', 'p.payment_method', 'p.payment_date',
                    'p.reference', 'p.notes', 'p.created_by', 'p.created_at',
                    'p.receiving_account_id',
                    'u.fullname as created_by_name',
                    'b.name as receiving_account_name',
                    'b.short_code as receiving_account_code',
                    'b.color_hex as receiving_account_color',
                    'l.approval_status as ledger_approval_status',
                    'l.settlement_status as ledger_settlement_status',
                ])
                ->get();

            // Tag each row with the entry BATCH it came from, so the payments
            // dialog can say "this Rs 10,751 was one slice of a single
            // Rs 40,000 transfer that also paid 6 other invoices" instead of
            // showing it as an unexplained standalone payment.
            // Additive only — every existing field is untouched, so the mobile
            // app and the qurbani screens are unaffected (a qurbani payment is
            // entered on its own, so it simply reports is_bulk = false).
            $batches = app(\App\Services\Payments\PaymentBatchService::class)
                ->describe($payments, isset($order->customer_id) ? (int) $order->customer_id : null);
            $payments = $payments->map(function ($p) use ($batches) {
                $b = $batches[(int) $p->id] ?? null;
                $p->is_bulk      = $b['is_bulk'] ?? false;
                $p->batch_size   = $b['batch_size'] ?? 1;
                $p->batch_total  = $b['batch_total'] ?? (float) $p->amount;
                $p->batch_orders = $b['orders'] ?? [];
                return $p;
            });

            return response()->json([
                'success' => true,
                'order_number' => $order->order_number,
                'total_price' => (float) $order->total_price,
                'total_paid' => (float) ($order->total_paid ?? 0),
                'payment_status' => $order->payment_status ?? 'unpaid',
                'balance_remaining' => max(0, (float)$order->total_price - (float)($order->total_paid ?? 0)),
                'payments' => $payments,
                // Ship the stamp overrides so the mobile app + web can
                // pre-fill the Add Payment / Edit Stamp dialogs instead of
                // re-typing everything on the next payment.
                'paid_stamp' => [
                    'sending_bank' => $order->paid_stamp_sending_bank,
                    'date'         => $order->paid_stamp_date
                        ? \Carbon\Carbon::parse($order->paid_stamp_date)->format('Y-m-d')
                        : null,
                    'ref_mode'     => $order->paid_stamp_ref_mode ?: 'reference',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function addQurbaniPayment(\Illuminate\Http\Request $request, $id)
    {
        try {
            $user = \Auth::user();
            $order = $this->findOrder($id);

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'required|string|in:cash,online',
                // Optional; defaults to today. Bounded to "not in the future"
                // because a forward-dated payment breaks ledger reporting.
                'payment_date'   => 'nullable|date|before_or_equal:today',
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                // Receiving bank — only meaningful for online payments (cash
                // has no bank). Silently ignored if payment_method is cash.
                'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
                // PAID-stamp overrides. All optional; stamp logic has
                // sensible fallbacks (see OrderModel::getPaidStampData).
                'sending_bank'    => 'nullable|string|max:100',
                'stamp_date'      => 'nullable|date',
                'stamp_ref_mode'  => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $amount = (float) $validated['amount'];
            $currentPaid = (float) ($order->total_paid ?? 0);
            $totalPrice = (float) $order->total_price;
            $remaining = max(0, $totalPrice - $currentPaid);

            if ($amount > $remaining + 0.01) {
                return response()->json(['success' => false, 'message' => 'Amount exceeds remaining balance of Rs. ' . number_format($remaining, 2)], 422);
            }

            $paymentMethod = $validated['payment_method'];
            // User-selected date wins over "today" so the team can backdate a
            // cash payment that arrived yesterday without editing the DB.
            $paymentDate = !empty($validated['payment_date'])
                ? \Carbon\Carbon::parse($validated['payment_date'])->toDateString()
                : now()->toDateString();
            $receivingAccountId = $paymentMethod === 'online'
                ? ($validated['receiving_account_id'] ?? null)
                : null;

            // Bank is MANDATORY for online payments — without it the per-bank
            // balances can never reconcile with the ONLINE account.
            if ($paymentMethod === 'online' && !$receivingAccountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select which bank received this online payment.',
                ], 422);
            }

            // Concurrency guard (D6): this path previously ran with NO transaction, so the
            // payment + ledger + balance move were not atomic and the engine's account lock could
            // not hold. Wrap it: lock the order row so simultaneous submissions serialize, then
            // reject an identical payment recorded moments ago (double-click / retry). A genuine
            // split payment differs in amount or arrives seconds+ later, so it is never blocked.
            \DB::beginTransaction();
            \App\Models\CRM\OrderModel::where('id', $order->id)->lockForUpdate()->first();
            $recentDuplicate = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
                ->where('amount', $amount)
                ->where('payment_method', $paymentMethod)
                ->where('created_by', $user->id)
                ->where('status', 'active')
                ->where('created_at', '>=', now()->subSeconds(8))
                ->exists();
            if ($recentDuplicate) {
                \DB::rollBack();
                return response()->json(['success' => false, 'message' => 'An identical payment was just recorded a moment ago. If this is a genuine second payment, refresh and re-enter it.'], 422);
            }

            $payment = \App\Models\CRM\OrderPaymentModel::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'receiving_account_id' => $receivingAccountId,
                'payment_date' => $paymentDate,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            // Merge PAID-stamp metadata onto the order. We only overwrite
            // existing stamp fields when the caller actually sent a value —
            // an older client that doesn't know about these fields will not
            // wipe the user's previous choices.
            $stampUpdates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $stampUpdates['paid_stamp_sending_bank'] = $validated['sending_bank'];
            }
            if (array_key_exists('stamp_date', $validated)) {
                $stampUpdates['paid_stamp_date'] = $validated['stamp_date'];
            } else {
                // Auto-advance the stamp date to the newest payment when the
                // caller didn't explicitly override it — matches the "auto
                // filled based on last payment date" behaviour requested.
                $stampUpdates['paid_stamp_date'] = $paymentDate;
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $stampUpdates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'];
            }
            if (!empty($stampUpdates)) {
                $order->fill($stampUpdates)->save();
            }

            $salesAccount = \App\Models\FIN\ConfigModel::getSalesRevenueAccount();
            if (!$salesAccount) {
                return response()->json(['success' => false, 'message' => 'Sales revenue account not configured'], 500);
            }

            if ($paymentMethod === 'cash') {
                $toAccount = \App\Models\FIN\ConfigModel::getQurbaniCashAccount();
            } else {
                $toAccount = \App\Models\FIN\ConfigModel::getQurbaniOnlineAccount();
            }

            if (!$toAccount) {
                return response()->json(['success' => false, 'message' => 'Qurbani payment account not configured'], 500);
            }

            // Name the bank in the description for the ledger listing.
            $qurbaniBankSuffix = '';
            if ($receivingAccountId) {
                $qurbaniBankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($receivingAccountId)?->short_code;
                if ($qurbaniBankShort) {
                    $qurbaniBankSuffix = " · via {$qurbaniBankShort}";
                }
            }

            $ledger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => $paymentDate,
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT,
                'description' => "Qurbani payment for order #{$order->order_number} - Rs. " . number_format($amount, 2) . " ({$paymentMethod}) by {$user->fullname}{$qurbaniBankSuffix}",
                'from_account_id' => $salesAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'mode' => $paymentMethod === 'cash' ? 'cash' : 'online',
                // Tag which of OUR banks received this online payment (null for
                // cash) so per-bank balances reconcile against the ONLINE account.
                'receiving_account_id' => $receivingAccountId,
                'approval_status' => \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                'balance_updated' => 0, // engine applies below and sets this
                'settlement_status' => 'settled',
                'settled_amount' => $amount,
                'settled_at' => now(),
                'approval_date' => now(),
                'approved_by' => $user->id,
                'order_id' => $order->id,
                'created_by' => $user->id,
            ]);

            $payment->ledger_transaction_id = $ledger->id;
            $payment->save();

            // Apply via the canonical engine (revenue −, holder +), row-locked; sets balance_updated.
            (new \App\Services\FIN\BalancePostingService())->apply($ledger);
            $order->recalculatePaymentStatus();

            \DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment of Rs. ' . number_format($amount, 2) . ' recorded successfully',
                'payment' => $payment,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (\DB::transactionLevel() > 0) { \DB::rollBack(); }
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            if (\DB::transactionLevel() > 0) { \DB::rollBack(); }
            \Log::error('Failed to add qurbani payment', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Record an incremental payment against a SHOP customer's online order.
     *
     * Shop customers don't get a single full-invoice ledger posting at
     * delivery (see LedgerPostingService guard). Instead each payment they
     * make is recorded here exactly like a Qurbani payment: an auto-approved
     * `order_payment` ledger entry that moves money into the ONLINE bank
     * account. Partial and full payments are both supported — the order's
     * payment_status (unpaid/partial/paid) is recalculated each time.
     *
     * Reuses getQurbaniPayments() for listing and deleteQurbaniPayment() for
     * voiding (both are account-agnostic / generic). Only the destination
     * account + description differ, which is what recordImmediateOrderPayment
     * handles via the 'shop' context.
     */
    public function addShopPayment(\Illuminate\Http\Request $request, $id)
    {
        try {
            $user = \Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = $this->findOrder($id);

            // Guard: this path is only for shop customers. A regular customer's
            // online order is settled through the normal approval flow, so we
            // refuse here to avoid creating order_payment rows that would
            // double-count against an already-posted invoice.
            if (!$order->customer || !$order->customer->isShop()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not belong to a shop customer.',
                ], 422);
            }

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                // Shop payments are online only — cash shop orders settle via
                // the standard rider flow. We still accept the field for
                // payload symmetry but pin it to online.
                'payment_method' => 'nullable|string|in:online',
                'payment_date'   => 'nullable|date|before_or_equal:today',
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
                'sending_bank'    => 'nullable|string|max:100',
                'stamp_date'      => 'nullable|date',
                'stamp_ref_mode'  => 'nullable|string|in:reference,customer_name,blank',
            ]);
            $validated['payment_method'] = 'online';

            $amount = (float) $validated['amount'];
            $currentPaid = (float) ($order->total_paid ?? 0);
            $totalPrice = (float) $order->total_price;
            $remaining = max(0, $totalPrice - $currentPaid);

            if ($amount > $remaining + 0.01) {
                return response()->json(['success' => false, 'message' => 'Amount exceeds remaining balance of Rs. ' . number_format($remaining, 2)], 422);
            }

            \DB::beginTransaction();
            try {
                $result = $this->recordImmediateOrderPayment($order, $validated, $user, 'shop');
                \DB::commit();
            } catch (\Exception $inner) {
                \DB::rollBack();
                throw $inner;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment of Rs. ' . number_format($amount, 2) . ' recorded successfully',
                'payment' => $result['payment'],
                'order'   => [
                    'total_paid'        => (float) $order->total_paid,
                    'payment_status'    => $order->payment_status,
                    'balance_remaining' => max(0, (float) $order->total_price - (float) $order->total_paid),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to add shop payment', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk shop payment (web). Split ONE online transfer across several selected
     * shop invoices. The FIFO allocation + validation + ledger posting all live
     * in the shared ShopBulkPaymentService so web and the mobile API behave
     * identically. This method is a thin validate-and-delegate wrapper.
     */
    public function bulkShopPayment(\Illuminate\Http\Request $request)
    {
        try {
            $user = \Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'order_ids'            => 'required|array|min:1',
                'order_ids.*'          => 'required|integer',
                'amount'               => 'required|numeric|min:0.01',
                // Online only — bank is mandatory for per-bank reconciliation.
                'receiving_account_id' => 'required|integer|exists:t_fin_online_receiving_accounts,id',
                'payment_date'         => 'nullable|date|before_or_equal:today',
                'reference'            => 'nullable|string|max:255',
                'notes'                => 'nullable|string|max:1000',
                'sending_bank'         => 'nullable|string|max:100',
                'stamp_date'           => 'nullable|date',
                'stamp_ref_mode'       => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $result = app(\App\Services\Payments\ShopBulkPaymentService::class)->execute($validated, $user);

            return response()->json([
                'success' => true,
                'message' => 'Recorded Rs. ' . number_format($result['total_amount'], 2) . ' across ' . $result['count'] . ' invoice(s).',
                'result'  => $result,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\App\Exceptions\ShopBulkPaymentException $e) {
            // Business-rule failure — safe to show the message verbatim.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to add bulk shop payment', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Shared core for "immediate" order payments (currently the Shop online
     * flow). Records the payment row + PAID-stamp metadata, posts a paired
     * auto-approved `order_payment` ledger entry, applies account balances,
     * links payment->ledger, and recalculates the order payment status.
     *
     * $context selects the destination account + description label:
     *   - 'shop'    : ONLINE bank account, "Shop payment"
     *   - 'qurbani' : Qurbani cash/online accounts, "Qurbani payment"
     *
     * Throws \RuntimeException on missing account configuration; callers wrap
     * this in a transaction and translate the error to a JSON 500.
     *
     * @return array{payment: \App\Models\CRM\OrderPaymentModel, ledger: \App\Models\FIN\LedgerModel}
     */
    private function recordImmediateOrderPayment(\App\Models\CRM\OrderModel $order, array $v, $user, string $context): array
    {
        $amount = (float) $v['amount'];
        $paymentMethod = $v['payment_method'];
        $paymentDate = !empty($v['payment_date'])
            ? \Carbon\Carbon::parse($v['payment_date'])->toDateString()
            : now()->toDateString();
        $receivingAccountId = $paymentMethod === 'online'
            ? ($v['receiving_account_id'] ?? null)
            : null;

        // Bank is MANDATORY for online payments (per-bank balance tracking).
        if ($paymentMethod === 'online' && !$receivingAccountId) {
            throw new \RuntimeException('Select which bank received this online payment.');
        }

        // Concurrency / double-submit guard (D6). Runs inside the caller's transaction: lock the
        // order row so two simultaneous submissions for the same order serialize, then reject an
        // identical payment recorded moments ago (double-click / retry). A legitimate split payment
        // differs in amount or arrives seconds+ later, so this never blocks a real second payment.
        \App\Models\CRM\OrderModel::where('id', $order->id)->lockForUpdate()->first();
        $recentDuplicate = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
            ->where('amount', $amount)
            ->where('payment_method', $paymentMethod)
            ->where('created_by', $user->id)
            ->where('status', 'active')
            ->where('created_at', '>=', now()->subSeconds(8))
            ->exists();
        if ($recentDuplicate) {
            throw new \RuntimeException('An identical payment was just recorded a moment ago. If this is a genuine second payment, refresh the page and re-enter it.');
        }

        $salesAccount = \App\Models\FIN\ConfigModel::getSalesRevenueAccount();
        if (!$salesAccount) {
            throw new \RuntimeException('Sales revenue account not configured');
        }

        if ($context === 'shop') {
            $toAccount = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
            $label = 'Shop payment';
            if (!$toAccount) {
                throw new \RuntimeException('Online bank account not configured');
            }
        } else {
            $toAccount = $paymentMethod === 'cash'
                ? \App\Models\FIN\ConfigModel::getQurbaniCashAccount()
                : \App\Models\FIN\ConfigModel::getQurbaniOnlineAccount();
            $label = 'Qurbani payment';
            if (!$toAccount) {
                throw new \RuntimeException('Qurbani payment account not configured');
            }
        }

        $payment = \App\Models\CRM\OrderPaymentModel::create([
            'order_id' => $order->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'receiving_account_id' => $receivingAccountId,
            'payment_date' => $paymentDate,
            'reference' => $v['reference'] ?? null,
            'notes' => $v['notes'] ?? null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        // PAID-stamp metadata — identical semantics to the Qurbani add flow.
        $stampUpdates = [];
        if (array_key_exists('sending_bank', $v)) {
            $stampUpdates['paid_stamp_sending_bank'] = $v['sending_bank'];
        }
        if (array_key_exists('stamp_date', $v)) {
            $stampUpdates['paid_stamp_date'] = $v['stamp_date'];
        } else {
            $stampUpdates['paid_stamp_date'] = $paymentDate;
        }
        if (array_key_exists('stamp_ref_mode', $v)) {
            $stampUpdates['paid_stamp_ref_mode'] = $v['stamp_ref_mode'];
        }
        if (!empty($stampUpdates)) {
            $order->fill($stampUpdates)->save();
        }

        // Name the bank in the description for the ledger listing.
        $payBankSuffix = '';
        if ($receivingAccountId) {
            $payBankShort = \App\Models\FIN\OnlineReceivingAccountModel::find($receivingAccountId)?->short_code;
            if ($payBankShort) {
                $payBankSuffix = " · via {$payBankShort}";
            }
        }

        $ledger = \App\Models\FIN\LedgerModel::create([
            'transaction_date' => $paymentDate,
            'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT,
            'description' => "{$label} for order #{$order->order_number} - Rs. " . number_format($amount, 2) . " ({$paymentMethod}) by {$user->fullname}{$payBankSuffix}",
            'from_account_id' => $salesAccount->id,
            'to_account_id' => $toAccount->id,
            'amount' => $amount,
            'mode' => $paymentMethod === 'cash' ? 'cash' : 'online',
            // Tag which of OUR banks received this online payment (null for cash)
            // so per-bank balances reconcile against the ONLINE account.
            'receiving_account_id' => $receivingAccountId,
            'approval_status' => \App\Models\FIN\LedgerModel::STATUS_APPROVED,
            'balance_updated' => 0, // engine applies below and sets this
            'settlement_status' => 'settled',
            'settled_amount' => $amount,
            'settled_at' => now(),
            'approval_date' => now(),
            'approved_by' => $user->id,
            'order_id' => $order->id,
            'created_by' => $user->id,
        ]);

        $payment->ledger_transaction_id = $ledger->id;
        $payment->save();

        // Apply via the canonical engine (revenue −, holder +), row-locked; sets balance_updated.
        (new \App\Services\FIN\BalancePostingService())->apply($ledger);
        $order->recalculatePaymentStatus();

        return ['payment' => $payment, 'ledger' => $ledger];
    }

    /**
     * Amend non-financial metadata on an existing qurbani payment.
     *
     * Deliberately narrow: this only lets the caller edit the receiving
     * bank (online only), the reference, and the notes — plus the separate
     * invoice PAID-stamp fields on the parent order. Amount / method /
     * payment_date cannot be changed here because any of those would
     * require reversing the paired ledger entry and rebuilding it, which
     * is what the existing void-and-readd flow is for.
     *
     * Voided payments cannot be edited — they're frozen for audit. Callers
     * that need to "fix" a voided row should void it (no-op if already
     * voided) and add a new payment instead.
     */
    public function updateQurbaniPayment(\Illuminate\Http\Request $request, $id, $paymentId)
    {
        try {
            $user = \Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = $this->findOrder($id);
            $payment = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
                ->where('id', $paymentId)
                ->first();

            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
            }
            if ($payment->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Voided payments cannot be edited'], 422);
            }

            $validated = $request->validate([
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                // Only meaningful for online payments. If the payment row
                // itself is cash we silently ignore this to match the add
                // flow (old clients send the same payload shape either way).
                'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
                // Invoice PAID-stamp display overrides — same semantics as
                // on add / the standalone stamp editor. Purely cosmetic.
                'sending_bank'    => 'nullable|string|max:100',
                'stamp_date'      => 'nullable|date',
                'stamp_ref_mode'  => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $updates = [];
            if (array_key_exists('reference', $validated)) {
                $updates['reference'] = $validated['reference'];
            }
            if (array_key_exists('notes', $validated)) {
                $updates['notes'] = $validated['notes'];
            }
            // Only persist the receiving bank on online payments. Cash rows
            // have no bank so we don't want to store a stale id against them.
            if ($payment->payment_method === 'online' && array_key_exists('receiving_account_id', $validated)) {
                $updates['receiving_account_id'] = $validated['receiving_account_id'];
            }

            if (!empty($updates)) {
                $payment->fill($updates)->save();
            }

            // Stamp metadata lives on the order, not the payment row, so we
            // apply it in a second narrow update. Only keys the caller sent
            // are touched — avoids wiping a previous choice.
            $stampUpdates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $stampUpdates['paid_stamp_sending_bank'] = $validated['sending_bank'];
            }
            if (array_key_exists('stamp_date', $validated)) {
                $stampUpdates['paid_stamp_date'] = $validated['stamp_date'];
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $stampUpdates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'];
            }
            if (!empty($stampUpdates)) {
                $order->fill($stampUpdates)->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment updated',
                'payment' => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                    'receiving_account_id' => $payment->receiving_account_id,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update qurbani payment', [
                'order_id' => $id, 'payment_id' => $paymentId, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Void (soft-delete) a Qurbani payment and reverse its financial impact.
     *
     * This is the inverse of addQurbaniPayment:
     *   1. Mark the payment row as 'voided' (we keep the row for audit).
     *   2. Reverse the receiving-account balance bump (subtract the amount).
     *   3. Reverse the sales-revenue debit (add the amount back).
     *   4. Delete the paired ledger entry so reports don't double count.
     *   5. Recalculate the order's total_paid + payment_status.
     *
     * Hard-deleting the payment row would break audit trails; voiding keeps
     * an immutable history while `scopeActive` + `payments()` both filter it
     * out so the stamp / totals / mobile listings all ignore voided rows.
     */
    public function deleteQurbaniPayment($id, $paymentId)
    {
        try {
            $user = \Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = $this->findOrder($id);
            $payment = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
                ->where('id', $paymentId)
                ->first();

            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
            }
            if ($payment->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Payment is not active and cannot be voided again'], 422);
            }

            \DB::beginTransaction();
            try {
                $amount = (float) $payment->amount;

                // Reverse the matching ledger row's account movements before
                // we drop it. We mirror addQurbaniPayment's post logic so
                // balance columns end up exactly where they started.
                $ledger = $payment->ledger_transaction_id
                    ? \App\Models\FIN\LedgerModel::find($payment->ledger_transaction_id)
                    : null;

                if ($ledger) {
                    $toAccount = \App\Models\FIN\AccountModel::find($ledger->to_account_id);
                    if ($toAccount) {
                        $toAccount->current_balance = (float) $toAccount->current_balance - $amount;
                        $toAccount->save();
                    }
                    $fromAccount = \App\Models\FIN\AccountModel::find($ledger->from_account_id);
                    if ($fromAccount) {
                        $fromAccount->current_balance = (float) $fromAccount->current_balance + $amount;
                        $fromAccount->save();
                    }
                    // Drop the ledger row so reports (and the daily closing
                    // sheet) stop counting the voided payment.
                    $ledger->delete();
                }

                $payment->status = 'voided';
                $payment->updated_by = $user->id;
                $payment->save();

                $order->recalculatePaymentStatus();

                \DB::commit();
            } catch (\Exception $inner) {
                \DB::rollBack();
                throw $inner;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment voided',
                'order'   => [
                    'total_paid'        => (float) $order->total_paid,
                    'payment_status'    => $order->payment_status,
                    'balance_remaining' => max(0, (float) $order->total_price - (float) $order->total_paid),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to void qurbani payment', [
                'order_id' => $id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update PAID-stamp display overrides on an order.
     *
     * This only touches paid_stamp_* columns on t_crm_prod_order — no payment
     * row is modified, no ledger entry is created. That separation is
     * intentional: the stamp is display metadata, not financial truth, so
     * fixing a typo in "sending bank" on the invoice must never ripple into
     * finance reports.
     *
     * Any authenticated web user can tweak stamp fields; we don't gate this
     * behind a role because it's non-destructive and every admin already has
     * full order edit rights.
     */
    public function updatePaidStamp(\Illuminate\Http\Request $request, $id)
    {
        try {
            $user = \Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = $this->findOrder($id);

            $validated = $request->validate([
                'sending_bank'   => 'nullable|string|max:100',
                'stamp_date'     => 'nullable|date',
                'stamp_ref_mode' => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $updates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $updates['paid_stamp_sending_bank'] = $validated['sending_bank'] !== '' ? $validated['sending_bank'] : null;
            }
            if (array_key_exists('stamp_date', $validated)) {
                $updates['paid_stamp_date'] = $validated['stamp_date'] !== '' ? $validated['stamp_date'] : null;
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $updates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'] ?: 'reference';
            }

            if (!empty($updates)) {
                $updates['updated_by'] = $user->id;
                $order->fill($updates)->save();
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Invoice stamp updated',
                'paid_stamp' => [
                    'sending_bank' => $order->paid_stamp_sending_bank,
                    'date'         => $order->paid_stamp_date?->format('Y-m-d'),
                    'ref_mode'     => $order->paid_stamp_ref_mode ?: 'reference',
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update paid stamp', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function invoice($id)
    {
        try {
            $order = $this->findOrder($id);
            $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
            
            return view('pages.orders.invoice', compact('order', 'qurbaniInvoiceFields'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Order not found');
        }
    }

    public function invoicePdf($id)
    {
        try {
            $order = $this->findOrder($id);
            
            // Generate filename for download (allow custom filename from request)
            $filename = request('filename', 'Invoice-' . ($order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT)));
            
            // Check if user wants direct image download
            if (request()->has('download_image')) {
                return $this->generateInvoiceImage($order, $filename);
            }
            
            // Check if user wants auto PDF download
            if (request()->has('auto_pdf')) {
                // Always generate actual server PDF for auto_pdf requests
                return $this->generateServerPDF($order, $filename);
            }
            
            // Check if user wants direct server-generated PDF download
            if (request()->has('force_pdf')) {
                return $this->generateServerPDF($order, $filename);
            }
            
            // Return a clean, print-ready view that can be saved as image or PDF
            $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
            return view('pages.orders.invoice-print', compact('order', 'filename', 'qurbaniInvoiceFields'));
            
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to generate invoice: ' . $e->getMessage());
        }
    }
    
    private function generateInvoiceImage($order, $filename)
    {
        // Increase execution time for image generation
        set_time_limit(120);
        
        \Log::info('Generating invoice image', [
            'order_id' => $order->id,
            'filename' => $filename
        ]);
        
        // Use the dedicated invoice-image template
        $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
        $html = view('pages.orders.invoice-image', ['order' => $order, 'qurbaniInvoiceFields' => $qurbaniInvoiceFields])->render();
        
        // Try to use Puppeteer or wkhtmltoimage if available
        $imagePath = $this->createInvoiceImage($html, $filename);
        
        if ($imagePath && file_exists($imagePath)) {
            $fileSize = filesize($imagePath);
            \Log::info('Invoice image generated successfully', [
                'path' => $imagePath,
                'size' => $fileSize
            ]);
            return response()->download($imagePath, $filename . '.png')->deleteFileAfterSend(true);
        }
        
        \Log::error('Invoice image generation failed', [
            'order_id' => $order->id,
            'filename' => $filename
        ]);
        
        // Fallback: Return HTML view with auto-download instructions
        $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
        return view('pages.orders.invoice-print', compact('order', 'filename', 'qurbaniInvoiceFields'))
               ->with('auto_download', true);
    }
    
    private function createInvoiceImage($html, $filename)
    {
        $tempDir = storage_path('app/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $htmlPath = $tempDir . '/' . $filename . '.html';
        $imagePath = $tempDir . '/' . $filename . '.png';
        
        file_put_contents($htmlPath, $html);
        \Log::info('HTML file created', ['path' => $htmlPath]);
        
        // Try different methods to generate image
        $wkhtmltoimage = env('WKHTMLTOIMAGE_BIN', 'wkhtmltoimage');
        $chromeBin = env('CHROME_BIN', 'google-chrome');
        $wkhtmltoimage = escapeshellarg($wkhtmltoimage);
        $chromeBin = escapeshellarg($chromeBin);

        $methods = [
            // Method 1: wkhtmltoimage
            "$wkhtmltoimage --width 1024 --quality 95 --format png --disable-smart-width --enable-local-file-access \"{$htmlPath}\" \"{$imagePath}\"",
            // Method 2: Chrome headless (if available)
            "$chromeBin --headless --disable-gpu --window-size=800,1200 --screenshot=\"{$imagePath}\" \"{$htmlPath}\"",
        ];
        
        foreach ($methods as $methodIndex => $command) {
            \Log::info("Trying image generation method " . ($methodIndex + 1), ['command' => $command]);
            exec($command . ' 2>&1', $output, $returnCode);
            \Log::info("Method " . ($methodIndex + 1) . " result", [
                'return_code' => $returnCode,
                'output' => implode("\n", $output),
                'file_exists' => file_exists($imagePath)
            ]);
            
            if ($returnCode === 0 && file_exists($imagePath)) {
                unlink($htmlPath); // Clean up HTML file
                \Log::info('Image generation successful', ['method' => $methodIndex + 1]);
                return $imagePath;
            }
        }
        
        // Clean up HTML file if image generation failed
        if (file_exists($htmlPath)) {
            unlink($htmlPath);
        }
        
        \Log::error('All image generation methods failed');
        return null;
    }
    
    private function generateServerPDF($order, $filename)
    {
        try {
            // Increase execution time for PDF generation
            set_time_limit(120);
            
            \Log::info('Starting PDF generation for order: ' . $order->id . ' with filename: ' . $filename);

            // Prefer wkhtmltopdf first for pixel-perfect rendering
            try {
                return $this->tryWkhtmltopdf($order, $filename);
            } catch (\Exception $wkhtmlError) {
                \Log::info('wkhtmltopdf failed, falling back to dompdf: ' . $wkhtmlError->getMessage());

                // Fallback: use dompdf with print-optimized template
                try {
                    $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
                    $pdf = \PDF::loadView('pages.orders.invoice-pdf', ['order' => $order, 'filename' => $filename, 'isPdf' => true, 'qurbaniInvoiceFields' => $qurbaniInvoiceFields])
                        ->setOptions([
                            'isHtml5ParserEnabled' => true,
                            'isRemoteEnabled' => true,
                            'defaultFont' => 'DejaVu Sans',
                            'isUnicode' => true,
                            'isFontSubsettingEnabled' => true
                        ])
                        ->setPaper('A4', 'portrait');

                    $pdfOutput = $pdf->output();
                    \Log::info('Dompdf PDF generated successfully, size: ' . strlen($pdfOutput) . ' bytes');
                    
                    return response($pdfOutput, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
                        'Content-Length' => strlen($pdfOutput),
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache',
                        'Expires' => '0'
                    ]);
                } catch (\Exception $dompdfError) {
                    \Log::info('Dompdf failed as well: ' . $dompdfError->getMessage());
                    // Final fallback handled below
                }
            }
            
        } catch (\Exception $e) {
            \Log::error('All PDF generation methods failed: ' . $e->getMessage());
            
            // Final fallback: Return a view that auto-downloads via JavaScript
            return $this->createJavaScriptPDFDownload($order, $filename);
        }
    }
    
    private function tryWkhtmltopdf($order, $filename)
    {
        try {
            // Use the exact same web invoice view for pixel-perfect output
            $qurbaniInvoiceFields = $this->isQurbaniOrder($order) ? $this->getQurbaniInvoiceFields() : [];
            $html = view('pages.orders.invoice-pdf', ['order' => $order, 'isPdf' => true, 'qurbaniInvoiceFields' => $qurbaniInvoiceFields])->render();
            
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $htmlPath = $tempDir . '/' . $filename . '.html';
            $pdfPath = $tempDir . '/' . $filename . '.pdf';
            
            file_put_contents($htmlPath, $html, LOCK_EX);
            
            // Try wkhtmltopdf command (binary can be overridden via .env)
            $wkhtmltopdf = env('WKHTMLTOPDF_BIN', 'wkhtmltopdf');
            $wkhtmltopdf = escapeshellarg($wkhtmltopdf);
            $command = "$wkhtmltopdf --page-size A4 --margin-top 14mm --margin-right 20mm --margin-bottom 16mm --margin-left 16mm --dpi 96 --zoom 1.0 --disable-smart-shrinking --enable-local-file-access --print-media-type --no-outline --encoding UTF-8 \"{$htmlPath}\" \"{$pdfPath}\"";
            exec($command . ' 2>&1', $output, $returnCode);
            \Log::info('wkhtmltopdf command executed with return code: ' . $returnCode . ', output: ' . implode("\n", $output));
            
            if ($returnCode === 0 && file_exists($pdfPath)) {
                $fileSize = filesize($pdfPath);
                \Log::info('wkhtmltopdf PDF generated successfully, size: ' . $fileSize . ' bytes');
                
                // Clean up HTML file
                unlink($htmlPath);
                
                // Return PDF download
                return response()->download($pdfPath, $filename . '.pdf')->deleteFileAfterSend(true);
            }
            
            // Clean up files if generation failed
            if (file_exists($htmlPath)) {
                unlink($htmlPath);
            }
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
            
            throw new \Exception('wkhtmltopdf command failed');
            
        } catch (\Exception $e) {
            \Log::info('wkhtmltopdf failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function createJavaScriptPDFDownload($order, $filename)
    {
        // Create a special view that uses JavaScript to trigger automatic PDF download
        return view('pages.orders.invoice-auto-download', compact('order', 'filename'));
    }

    // Open edit order in a dedicated tab with full assets loaded
    public function editTab($id)
    {
        // Open the main Orders page with an instruction to auto-open the edit modal for this order.
        return redirect('/orders?edit_order_id=' . urlencode((string) $id));
    }

    public function update(Request $request, $id)
    {
        try {
            $order = $this->findOrder($id, []);

            // ⭐ Optimistic concurrency guard (barcode-qty): reject a web save based on
            // stale data — e.g. a barcode scan changed a line quantity AFTER this edit
            // form loaded. Opt-in: only fires when the web client sends
            // expected_updated_at (mobile/webhook callers don't, so they're unaffected).
            // The web client also re-checks + prompts before reaching here; this is the
            // race safety net so a scanned quantity is never silently overwritten.
            if ($order && $request->filled('expected_updated_at') && !$request->boolean('_skip_ledger_adjustment')) {
                try {
                    $clientSeen = \Carbon\Carbon::parse($request->input('expected_updated_at'));
                    if ($order->updated_at && $order->updated_at->gt($clientSeen->copy()->addSeconds(2))) {
                        return response()->json([
                            'success' => false,
                            'conflict' => true,
                            'message' => 'This order was updated since you opened it (a quantity may have been changed by a barcode scan). Please reload the order to see the latest values, then save again.',
                        ], 409);
                    }
                } catch (\Throwable $e) { /* unparseable timestamp — skip the guard */ }
            }

            // ================================================================
            // PARTIAL UPDATE DETECTION (Pop-out Mode)
            // ================================================================
            // Pop-out mode sends _partial_update=true and only includes:
            // - items, subtotal_price, shipping_total, total_price, discounts, note, order_date, customer_id, name
            // It does NOT include: order_status, expected_packets, payment_method, address, rider
            $isPartialUpdate = $request->boolean('_partial_update') || $request->boolean('_popout_mode');
            
            if ($isPartialUpdate) {
                \Log::info('Partial update detected (pop-out mode)', [
                    'order_id' => $id,
                    'order_number' => $order->order_number,
                    'fields_sent' => array_keys($request->except(['_partial_update', '_popout_mode']))
                ]);
            }
            
            // Validate request - use different rules for partial vs full update
            $validationRules = [
                'customer_id' => 'nullable|exists:t_crm_prod_customer,id',
                'name' => 'nullable|string|max:255',
                'order_date' => 'required|date',
                'contact_email' => 'nullable|email',
                'subtotal_price' => 'required|numeric',
                'discount_total' => 'nullable|numeric',
                'shipping_total' => 'nullable|numeric',
                'tip_amount' => 'nullable|numeric|min:0',
                'total_price' => 'required|numeric',
                'coupon_code' => 'nullable|string',
                'note' => 'nullable|string',
                'items' => 'required|array',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.line_total' => 'required|numeric|min:0',
                'items.*.sku' => 'nullable|string',
                'items.*.variant_id' => 'nullable|string',
                'items.*.product_id' => 'nullable|string',
                'items.*.is_free' => 'nullable|boolean',
                'items.*.qurbani_day' => 'nullable|string|max:50',
                'items.*.qurbani_slot' => 'nullable|string|max:50',
                'items.*.qurbani_region' => 'nullable|string|max:100',
                'items.*.qurbani_sub_region' => 'nullable|string|max:100',
                'items.*.qurbani_delivery_type' => 'nullable|string|max:50',
                // New Apr-2026 qurbani attributes (configurable dropdowns).
                'items.*.qurbani_type' => 'nullable|string|max:50',
                'items.*.qurbani_paya' => 'nullable|string|max:50',
                'items.*.instructions' => 'nullable|string|max:500',
                // Multiple discounts support
                'discounts' => 'nullable|array',
                'discounts.*.title' => 'required_with:discounts|string|max:255',
                'discounts.*.amount' => 'required_with:discounts|numeric|min:0',
                'discounts.*.type' => 'nullable|in:fixed,percentage',
                'discounts.*.percentage' => 'nullable|numeric|min:0|max:100',
                'discounts.*.coupon_code' => 'nullable|string|max:100',
                'discounts.*.notes' => 'nullable|string',
                // Partial update flags
                '_partial_update' => 'nullable|boolean',
                '_popout_mode' => 'nullable|boolean',
            ];
            
            // For FULL updates (not partial), require additional fields
            if (!$isPartialUpdate) {
                $validationRules['order_status'] = 'required|string|exists:t_crm_order_status_master,status_code';
                $validationRules['payment_method'] = 'nullable|string';
                $validationRules['expected_packets'] = 'nullable|integer|min:0';
                // Address fields
                $validationRules['address_first_name'] = 'nullable|string';
                $validationRules['address_last_name'] = 'nullable|string';
                $validationRules['address_email'] = 'nullable|email';
                $validationRules['address_phone'] = 'nullable|string';
                $validationRules['address_line1'] = 'nullable|string';
                $validationRules['address_line2'] = 'nullable|string';
                $validationRules['address_city'] = 'nullable|string';
                $validationRules['address_province'] = 'nullable|string';
                $validationRules['address_postal_code'] = 'nullable|string';
                $validationRules['address_country'] = 'nullable|string';
                // ⭐ Option to sync address changes back to customer profile
                $validationRules['sync_to_customer'] = 'nullable|boolean';
                // Qurbani fields (editable on full update)
                $validationRules['qurbani_day'] = 'nullable|string|max:50';
                $validationRules['qurbani_slot'] = 'nullable|string|max:50';
                $validationRules['qurbani_region'] = 'nullable|string|max:100';
                $validationRules['qurbani_delivery_type'] = 'nullable|string|max:50';
            }
            
            $validated = $request->validate($validationRules);

            // ================================================================
            // ⭐ CUSTOMER CREDIT — protect the account-balance line (Aug-2026)
            // ================================================================
            // This save DELETES and RECREATES every discount row from what the
            // browser sent, so an untouched payload would silently destroy the
            // account-balance line while the credit row still says it is spent.
            //
            // The rule is therefore simple and one-directional: THE SERVER OWNS
            // THAT LINE. An ordinary order save can neither create it nor remove
            // it — it is stripped from the payload and re-added from the credit
            // row itself. Applying and removing credit happen ONLY through
            // /orders/{id}/credit/apply and /credit/remove.
            //
            // ⚠ Do NOT "improve" this by releasing the credit when the line is
            // missing from the payload: the edit form does not send coupon_code
            // at all, so every normal save would look like a removal and would
            // hand the money back behind the manager's back.
            //
            // Done before the totals are computed below, so discount_total and
            // total_price come out right with no other edits.
            $creditService  = new \App\Services\CustomerCreditService();
            $creditSentinel = \App\Models\CRM\CustomerCreditModel::DISCOUNT_CODE;

            if (isset($validated['discounts']) && is_array($validated['discounts'])
                && !($order instanceof \App\Models\CRM\ShopifyOrderModel)) {

                $cleanDiscounts = [];
                foreach ($validated['discounts'] as $d) {
                    // Never take this line from the client — it is real money.
                    if (($d['coupon_code'] ?? null) === $creditSentinel) {
                        continue;
                    }
                    // A client can't mint credit by copying the title either.
                    if (trim((string) ($d['title'] ?? '')) === 'Account balance applied') {
                        continue;
                    }
                    $cleanDiscounts[] = $d;
                }

                $liveConsume = $creditService->liveConsumeForOrder((int) $order->id);
                if ($liveConsume) {
                    $creditAmount = round(abs((float) $liveConsume->amount), 2);

                    // Guard: if this edit shrinks the order below the applied
                    // credit, the recomputed total would go NEGATIVE. Refuse
                    // with instructions rather than silently booking a negative
                    // invoice — the manager removes the balance (banner →
                    // Remove), makes the change, then re-applies what fits.
                    if (isset($validated['items']) && is_array($validated['items'])) {
                        $prospectiveSubtotal = collect($validated['items'])->sum(function ($item) {
                            return !empty($item['is_free']) ? 0 : floatval($item['line_total'] ?? ($item['quantity'] * $item['unit_price']));
                        });
                        $otherDiscounts = collect($cleanDiscounts)->sum('amount');
                        $prospectiveBeforeCredit = $prospectiveSubtotal - $otherDiscounts
                            + floatval($validated['shipping_total'] ?? 0)
                            + floatval($validated['tip_amount'] ?? 0);
                        if ($creditAmount > $prospectiveBeforeCredit + 0.005) {
                            return response()->json([
                                'success' => false,
                                'message' => 'This change would make the order total (Rs ' . number_format(max(0, $prospectiveBeforeCredit), 2)
                                    . ') smaller than the Rs ' . number_format($creditAmount, 2)
                                    . ' account balance applied to it. Remove the account balance from the order first, save your change, then apply the balance again.',
                            ], 422);
                        }
                    }

                    $cleanDiscounts[] = [
                        'title'       => 'Account balance applied',
                        'amount'      => $creditAmount,
                        'type'        => 'fixed',
                        'coupon_code' => $creditSentinel,
                        'notes'       => 'Paid from the customer\'s account balance.',
                    ];
                }

                $validated['discounts'] = $cleanDiscounts;
            }

            // ================================================================
            // ⭐ SERVER-SIDE TOTAL RECALCULATION (Feb 2026)
            // Always recalculate totals from line items to prevent frontend/backend mismatches
            // ================================================================
            if (isset($validated['items']) && is_array($validated['items']) && !empty($validated['items'])) {
                // ⭐ Enforce is_free: if item is marked free, force line_total to 0
                foreach ($validated['items'] as &$itemRef) {
                    if (!empty($itemRef['is_free'])) {
                        $itemRef['line_total'] = 0;
                    }
                }
                unset($itemRef);
                
                // Calculate subtotal from line items (free items contribute 0)
                $calculatedSubtotal = collect($validated['items'])->sum(function($item) {
                    if (!empty($item['is_free'])) {
                        return 0;
                    }
                    return floatval($item['line_total'] ?? ($item['quantity'] * $item['unit_price']));
                });
                
                // Calculate discount total from discounts array (if provided)
                $calculatedDiscountTotal = 0;
                if (isset($validated['discounts']) && is_array($validated['discounts'])) {
                    $calculatedDiscountTotal = collect($validated['discounts'])->sum('amount');
                } elseif (isset($validated['discount_total'])) {
                    $calculatedDiscountTotal = floatval($validated['discount_total']);
                }
                
                // Calculate expected total
                $shipping = floatval($validated['shipping_total'] ?? 0);
                $tip = floatval($validated['tip_amount'] ?? 0);
                $calculatedTotal = $calculatedSubtotal - $calculatedDiscountTotal + $shipping + $tip;
                
                // Log if there's a mismatch (for debugging)
                $frontendSubtotal = floatval($validated['subtotal_price'] ?? 0);
                $frontendTotal = floatval($validated['total_price'] ?? 0);
                
                if (abs($frontendSubtotal - $calculatedSubtotal) > 1 || abs($frontendTotal - $calculatedTotal) > 1) {
                    \Log::warning('Order total mismatch detected - using server-calculated values', [
                        'order_id' => $id,
                        'frontend_subtotal' => $frontendSubtotal,
                        'calculated_subtotal' => $calculatedSubtotal,
                        'frontend_total' => $frontendTotal,
                        'calculated_total' => $calculatedTotal,
                        'discount' => $calculatedDiscountTotal,
                        'shipping' => $shipping,
                        'tip' => $tip,
                    ]);
                }
                
                // Always use server-calculated values
                $validated['subtotal_price'] = $calculatedSubtotal;
                $validated['total_price'] = $calculatedTotal;
            }
            
            // ⭐ Calculate discount_total from discounts array
            // If discounts array is provided (even if empty), update discount_total
            if (isset($validated['discounts']) && is_array($validated['discounts'])) {
                if (!empty($validated['discounts'])) {
                    // Has discounts - sum them up
                    $calculatedDiscountTotal = collect($validated['discounts'])->sum('amount');
                    $validated['discount_total'] = $calculatedDiscountTotal;
                    \Log::info('Update: Multiple discounts provided', [
                        'order_id' => $id,
                        'count' => count($validated['discounts']),
                        'calculated_total' => $calculatedDiscountTotal
                    ]);
                } else {
                    // Empty discounts array - all discounts removed, set to 0
                    $validated['discount_total'] = 0;
                    \Log::info('Update: All discounts removed', ['order_id' => $id]);
                }
            }
            
            // ================================================================
            // LEDGER ADJUSTMENT DETECTION
            // ================================================================
            // Check if this order has a ledger entry (i.e., was delivered) and if the total_price changed
            // IMPORTANT: Skip ledger adjustments for webhook updates (WooCommerce/Shopify)
            // Only create adjustments for manual edits from the webapp frontend
            $ledgerAdjustmentCreated = false;
            $adjustmentId = null;
            
            // Check if this update is from a webhook (external source)
            // ⭐ [Ledger L1, D2] The skip flag can no longer be spoofed by an interactive edit.
            // The ONLY legitimate setter is the server-side webhook sync (OrderModel::storeOrderFromApi,
            // a model-level update that never reaches this HTTP endpoint). So a request carrying the
            // flag WHILE authenticated as a web user is a manual edit and must NOT skip the ledger
            // adjustment; only a non-authenticated (server/webhook) context may.
            $isWebhookUpdate = ($request->has('_skip_ledger_adjustment')
                                || (isset($validated['_skip_ledger_adjustment']) && $validated['_skip_ledger_adjustment']))
                               && !auth()->check();
            
            if ($order->ledger_transaction_id && !$isWebhookUpdate) {
                $ledger = \App\Models\FIN\LedgerModel::find($order->ledger_transaction_id);
                
                if ($ledger) {
                    $oldAmount = $ledger->amount;
                    $newAmount = $validated['total_price'];
                    
                    // Check if there's a significant change (more than 1 cent to account for floating point)
                    if (abs($oldAmount - $newAmount) > 0.01) {
                        // Get the category configuration for invoice adjustments
                        $category = RequestCategoryModel::where('category_code', 'invoice_adjustment')->first();
                        $categoryConfig = $category ? $category->approvalConfig : null;
                        
                        // Use category config to determine required approval levels
                        $requiresL1 = $categoryConfig ? $categoryConfig->requires_level_1 : true;
                        $requiresL2 = $categoryConfig ? $categoryConfig->requires_level_2 : false;
                        
                        // ⭐ Check if user should get auto-approval (L2 rights OR Taimur)
                        $currentUser = auth()->user();
                        $userHasL2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($currentUser->id, 2);
                        // ⭐ [Ledger L1, D3] Auto-approve (post-invoice correction) is granted by L2
                        // approval rights OR the explicit 'ledger_privileged_corrections' permission.
                        // Replaces a DEAD hardcoded email check for 'taimur@nizamifarms.pk' (his real
                        // address is .com, so it never matched — he already qualifies via L2 rights).
                        // Behaviour-preserving + future-proof (a non-L2 user can be granted the perm).
                        $shouldAutoApprove = $userHasL2Rights
                            || (method_exists($currentUser, 'hasPermission') && $currentUser->hasPermission('ledger_privileged_corrections'));

                        // ⭐ [Ledger L3] PENDING invoice (pending/pending_l1, balances never applied):
                        // the edit is folded INTO the invoice itself, for EVERY editor. The pending
                        // row's amount is rewritten (there are no balances to move yet) and the L1
                        // approver sees — and approves — the corrected figure: the invoice approval
                        // IS the human control. A separate pending adjustment (whose approval could
                        // race the invoice's own approval) is never created for a pending invoice.
                        $isPendingInvoice = !$ledger->balance_updated
                            && in_array($ledger->approval_status, [
                                   \App\Models\FIN\LedgerModel::STATUS_PENDING,
                                   \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
                               ], true);
                        if ($isPendingInvoice) {
                            $shouldAutoApprove = true; // audit row saved approved; invoice approval is the gate
                        }

                        // Determine approval statuses based on auto-approve
                        if ($shouldAutoApprove) {
                            // L2 user or Taimur: Auto-approve all levels
                            $adjustmentStatus = \App\Models\FIN\LedgerAdjustmentModel::STATUS_APPROVED;
                            $level1Status = \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED;
                            $level2Status = \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED;
                        } else {
                            // Normal pending flow - use category config requirements
                            $adjustmentStatus = \App\Models\FIN\LedgerAdjustmentModel::STATUS_PENDING;
                            $level1Status = $requiresL1 ? \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_PENDING : \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED;
                            $level2Status = $requiresL2 ? \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_PENDING : \App\Models\FIN\LedgerAdjustmentModel::APPROVAL_STATUS_APPROVED;
                        }
                        
                        // Create a ledger adjustment request
                        $adjustment = \App\Models\FIN\LedgerAdjustmentModel::create([
                            'ledger_id' => $ledger->id,
                            'order_id' => $order->id,
                            'old_amount' => $oldAmount,
                            'new_amount' => $newAmount,
                            'adjustment_amount' => $newAmount - $oldAmount,
                            'reason' => "Order #{$order->order_number} invoice amount changed from Rs. " . number_format($oldAmount, 2) . " to Rs. " . number_format($newAmount, 2),
                            'adjustment_status' => $adjustmentStatus,
                            'requires_level_1' => $requiresL1,
                            'requires_level_2' => $requiresL2,
                            'level_1_status' => $level1Status,
                            'level_2_status' => $level2Status,
                            'level_1_approved_by' => $shouldAutoApprove ? $currentUser->id : null,
                            'level_1_approved_at' => $shouldAutoApprove ? now() : null,
                            'level_2_approved_by' => $shouldAutoApprove ? $currentUser->id : null,
                            'level_2_approved_at' => $shouldAutoApprove ? now() : null,
                            'finalized_at' => $shouldAutoApprove ? now() : null,
                            'requested_by' => $currentUser->id,
                            'requested_at' => now()
                        ]);
                        
                        // ⭐ If auto-approved, immediately apply the adjustment to ledger
                        if ($shouldAutoApprove) {
                            // POST-SETTLEMENT correction guard (Ledger L1, owner-ruled Option A):
                            // if this invoice is ALREADY settled, the rider has handed the cash over
                            // and his balance MUST NOT move. His balance is CALCULATED from invoice
                            // amounts, so rewriting the amount is exactly what would move him and
                            // create the "phantom" drift (the Haider/Asim case). So we DO NOT rewrite
                            // the amount/settlement of a settled invoice — we only annotate it. The
                            // LedgerAdjustment row above is the audit trail; the order total updates
                            // below (revenue is order-based). Unsettled invoices keep the existing
                            // behaviour (rider still holds the money → his open balance correctly
                            // follows the corrected amount).
                            // ⭐ Sep-2026: ask "was cash handed over?", not "is it settled?" — a
                            // free order's Rs 0 invoice is auto-settled at posting with no cash
                            // behind it, so a re-price must still reach the rider's balance.
                            $isSettledInvoice = $ledger->isSettledWithCash();
                            if ($isSettledInvoice) {
                                $ledger->comments = ($ledger->comments ?? '') .
                                    " | Post-settlement correction Rs. " . number_format($oldAmount, 2) . " → Rs. " . number_format($newAmount, 2) .
                                    " ABSORBED — invoice + rider balance unchanged (by " . $currentUser->fullname . " on " . now()->format('Y-m-d H:i:s') . ")";
                                $ledger->save(); // comment ONLY — amount + settlement untouched
                            } elseif ($ledger->balance_updated) {
                                // ⭐ [Ledger L3] APPLIED but unsettled (pending_l2/approved): correct
                                // through the ENGINE BRACKET — take the old amount out of the books,
                                // rewrite, post the new amount — so the applied balances follow the
                                // correction EXACTLY. (The old code rewrote the amount only, leaving
                                // stored balances applied at the old figure: a per-edit stored drift
                                // on REV + the holder that only the riders' calculated display hid,
                                // and a wrong-amount reversal risk on later reject/cancel.)
                                $engine = new \App\Services\FIN\BalancePostingService();
                                $engine->reverse($ledger);          // old amount out of the books
                                $ledger->amount = $newAmount;
                                $ledger->comments = ($ledger->comments ?? '') .
                                    " | Amount adjusted from Rs. " . number_format($oldAmount, 2) . " to Rs. " . number_format($newAmount, 2) .
                                    " (Auto-approved by " . $currentUser->fullname . " on " . now()->format('Y-m-d H:i:s') . ")";
                                // Rs 0 ⇄ priced: settle (nothing to collect) or reopen (rider holds cash).
                                $ledger->refreshNothingToCollectSettlement('order edit by ' . $currentUser->fullname);
                                $ledger->save();
                                $engine->apply($ledger);            // new amount into the books
                            } else {
                                // PENDING invoice (pending/pending_l1) — nothing applied yet, so the
                                // amount rewrite is the whole correction. The approvals queue shows
                                // this row's amount, so the approver sees + approves the new figure;
                                // the engine posts it at L1 approval.
                                $ledger->amount = $newAmount;
                                $ledger->comments = ($ledger->comments ?? '') .
                                    " | Amount adjusted from Rs. " . number_format($oldAmount, 2) . " to Rs. " . number_format($newAmount, 2) .
                                    " (pre-approval edit by " . $currentUser->fullname . " on " . now()->format('Y-m-d H:i:s') . " — takes effect at invoice approval)";
                                $ledger->save();
                            }

                            \Log::info("Ledger adjustment AUTO-APPROVED for order update", [
                                'post_settlement_absorbed' => $isSettledInvoice,
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'adjustment_id' => $adjustment->id,
                                'old_amount' => $oldAmount,
                                'new_amount' => $newAmount,
                                'difference' => $newAmount - $oldAmount,
                                'ledger_id' => $ledger->id,
                                'auto_approved_by' => $currentUser->fullname,
                                'user_has_l2_rights' => $userHasL2Rights,
                                'auto_approve_via' => $userHasL2Rights ? 'l2_rights' : 'ledger_privileged_corrections',
                                'source' => 'webapp_manual_edit_auto_approved'
                            ]);
                        } else {
                            \Log::info("Ledger adjustment created for order update (pending approval)", [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'adjustment_id' => $adjustment->id,
                                'old_amount' => $oldAmount,
                                'new_amount' => $newAmount,
                                'difference' => $newAmount - $oldAmount,
                                'ledger_id' => $ledger->id,
                                'source' => 'webapp_manual_edit'
                            ]);
                        }
                        
                        $ledgerAdjustmentCreated = true;
                        $adjustmentId = $adjustment->id;
                    }
                }
            } elseif ($isWebhookUpdate && $order->ledger_transaction_id) {
                \Log::info("Ledger adjustment skipped for webhook update", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'source' => 'webhook',
                    'has_ledger' => true
                ]);
            }
            
            // ================================================================
            // CAPTURE OLD PAYMENT METHOD (Before Order Update)
            // ================================================================
            // IMPORTANT: Capture old values BEFORE updating the order
            $oldPaymentMethod = $order->payment_method;
            $oldCustomerId = $order->customer_id;
            $statusChangeWarning = null; // set by the full-update status discipline below

            // Oct-2026 — a plain edit must NOT look like a payment-method change.
            // Orders delivered from the phone / Shopify store 'online_payment',
            // but the edit form's dropdown only knows 'online' (and 'cash_on_delivery'
            // vs 'cash'), so every save posted a different SPELLING of the same
            // method. The block further down then reversed the pending invoice and
            // re-posted it (new ledger id, new date, "changed from 'online' to
            // 'online'") — and on 14-Oct SH-23614's re-post never landed, dropping
            // it out of Online Approvals. Same family ⇒ keep the stored spelling so
            // nothing downstream sees a change. A REAL switch (cash ↔ online) still
            // goes through the reverse + re-post path exactly as before.
            if (!$isPartialUpdate && array_key_exists('payment_method', $validated)) {
                $submittedPm = $validated['payment_method'];
                if ($submittedPm === null || $submittedPm === ''
                    || (self::paymentMethodFamily($submittedPm) === self::paymentMethodFamily($oldPaymentMethod)
                        && $submittedPm !== $oldPaymentMethod)) {
                    $validated['payment_method'] = $oldPaymentMethod;
                }
            }

            // ================================================================
            // UPDATE ORDER (with Partial Update Support)
            // ================================================================
            // For partial updates (pop-out mode), only update financial fields + customer
            // Preserve: order_status, expected_packets, payment_method, address, rider
            if ($isPartialUpdate) {
                // Remove operational fields from update - they should be preserved
                // customer_id and name are allowed through so customer changes work in pop-out
                $fieldsToExclude = [
                    'order_status', 'expected_packets', 'actual_packets', 'payment_method',
                    'assigned_rider_user_id',
                    'address_first_name', 'address_last_name', 'address_email', 'address_phone',
                    'address_line1', 'address_line2', 'address_city', 'address_province', 
                    'address_postal_code', 'address_country',
                    '_partial_update', '_popout_mode', '_skip_ledger_adjustment'
                ];
                
                $updateData = array_diff_key($validated, array_flip($fieldsToExclude));
                
                \Log::info('Partial update - preserving operational fields', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'fields_updated' => array_keys($updateData),
                    'fields_preserved' => [
                        'order_status' => $order->order_status,
                        'expected_packets' => $order->expected_packets,
                        'payment_method' => $order->payment_method,
                        'assigned_rider_user_id' => $order->assigned_rider_user_id
                    ]
                ]);
                
                $order->update($updateData);

                // If customer_id changed, sync the new customer's details onto the order
                $newCustomerId = $updateData['customer_id'] ?? null;
                if ($newCustomerId && (int)$newCustomerId !== (int)$oldCustomerId) {
                    $newCustomer = \App\Models\CRM\CustomerModel::find($newCustomerId);
                    if ($newCustomer) {
                        $order->update([
                            'name' => trim(($newCustomer->first_name ?? '') . ' ' . ($newCustomer->last_name ?? '')),
                            'address_first_name' => $newCustomer->first_name,
                            'address_last_name' => $newCustomer->last_name,
                            'address_email' => $newCustomer->email,
                            'address_phone' => $newCustomer->phone_original,
                            'address_line1' => $newCustomer->address1,
                            'address_line2' => $newCustomer->address2,
                            'address_city' => $newCustomer->city,
                            'address_province' => $newCustomer->province,
                            'address_postal_code' => $newCustomer->postal_code,
                            'address_country' => $newCustomer->country ?: 'Pakistan',
                        ]);
                        \Log::info('Partial update - synced new customer details onto order', [
                            'order_id' => $order->id,
                            'old_customer_id' => $oldCustomerId,
                            'new_customer_id' => $newCustomerId,
                            'new_customer_name' => trim($newCustomer->first_name . ' ' . $newCustomer->last_name),
                        ]);
                    }
                }
            } else {
                // Full update - update all validated fields
                // Remove internal flags before updating
                $updateData = $validated;
                unset($updateData['_partial_update'], $updateData['_popout_mode'], $updateData['_skip_ledger_adjustment']);

                // ================================================================
                // STATUS-CHANGE DISCIPLINE (Jul-2026)
                // ================================================================
                // order_status must NEVER be written via the mass update below.
                // The old direct write skipped OrderModel::changeStatus(), so a
                // status set here produced NO status-history row, NO customer-app
                // webhook, and — worst — picking "delivered" in the edit form
                // skipped invoice posting + inventory deduction entirely. It also
                // let a form loaded an hour ago silently REVERT a status the
                // mobile team had advanced in the meantime (stale overwrite).
                //
                // Contract with the edit form: it also sends _loaded_status = the
                // status the form was SHOWING when it was opened. Decision table:
                //   • submitted == current DB status
                //       -> nothing to do.
                //   • submitted == _loaded_status (dropdown untouched, but the DB
                //     moved on while the form was open)
                //       -> KEEP the DB status. The user expressed no intent about
                //          status; their qty/price edits save normally. This kills
                //          the stale-revert race with zero user-facing friction.
                //   • submitted != _loaded_status (user deliberately picked a new
                //     status), or _loaded_status missing (old cached client)
                //       -> route through changeStatus() so history / webhook /
                //          ledger / inventory all fire properly.
                // Shopify STAGING orders have no changeStatus pipeline (no ledger,
                // history, or webhooks) — they keep the direct column write.
                $submittedStatus = isset($updateData['order_status']) ? (string) $updateData['order_status'] : null;
                $loadedStatus = $request->input('_loaded_status');
                unset($updateData['order_status']);

                $order->update($updateData);

                if ($submittedStatus !== null && $submittedStatus !== $order->order_status) {
                    $dropdownUntouched = ($loadedStatus !== null && $loadedStatus !== '' && $submittedStatus === (string) $loadedStatus);

                    if ($dropdownUntouched) {
                        \Log::info('Order edit: preserved concurrent status change (stale form)', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'form_loaded_with' => $loadedStatus,
                            'kept_db_status' => $order->order_status,
                        ]);
                    } elseif ($order instanceof \App\Models\CRM\OrderModel) {
                        // 🚚 An order ON THE VAN is not moved from a dropdown (owner
                        // ruling Aug-4): the handover scan is what proves who took it
                        // and where. The rest of the edit still saves.
                        $vanBlock = \App\Services\Riders\VanService::manualChangeBlock($order, $submittedStatus);
                        // ⭐⭐ RETURNS (Sep-2026): the edit form's dropdown cannot ask
                        // where the money goes or which shelf the meat lands on, so it
                        // must not be able to set "Returned". The rest of the edit
                        // still saves — only the status is refused, with the door named.
                        if ($vanBlock === null
                            && $submittedStatus === \App\Services\CRM\OrderReturnService::STATUS_CODE) {
                            $vanBlock = 'To return this order use the “Return order” button — it records what happens to the money and the goods. The rest of your changes were saved.';
                        }
                        if ($vanBlock !== null) {
                            $statusChangeWarning = $vanBlock;
                        } else {
                        try {
                            $ok = $order->changeStatus($submittedStatus, 'Changed via order edit form', auth()->id());
                            if (!$ok) {
                                $statusChangeWarning = "Status change to '{$submittedStatus}' could not be applied — the rest of the order was saved.";
                            } elseif (!empty($order->lastInvoicePostError)) {
                                // Status changed, but the delivered-invoice could not
                                // post to the ledger. Without this warning the failure
                                // only appears in the error log and the order silently
                                // stays un-invoiced.
                                $statusChangeWarning = "Order is marked '{$submittedStatus}', but the invoice could NOT be posted to the ledger: {$order->lastInvoicePostError}. Fix the cause, set the status back, then deliver again so the invoice posts.";
                            } elseif (!empty($order->lastInvoiceNote)) {
                                // Non-fatal heads-up: the invoice posted, but not to
                                // the usual account (no rider -> NF Cash Main Till).
                                $statusChangeWarning = $order->lastInvoiceNote . ' Assign a rider before delivery if it should settle against a rider instead.';
                            }
                        } catch (\Throwable $e) {
                            \Log::error('Order edit: changeStatus failed', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'submitted_status' => $submittedStatus,
                                'error' => $e->getMessage(),
                            ]);
                            $statusChangeWarning = "Status change to '{$submittedStatus}' failed: {$e->getMessage()} — the rest of the order was saved.";
                        }
                        } // end van-guard else
                    } else {
                        // Shopify staging order — direct write, as before.
                        $order->order_status = $submittedStatus;
                        $order->save();
                    }
                }
            }
            
            // ================================================================
            // PAYMENT METHOD CHANGE DETECTION (After Delivery)
            // ================================================================
            // Check if payment method changed for a delivered order with ledger entry
            // SKIP for partial updates (pop-out mode) since payment_method is not sent
            $paymentMethodChanged = false;
            $paymentMethodChangeMessage = null;
            
            if ($order->ledger_transaction_id && !$isWebhookUpdate && !$isPartialUpdate) {
                $newPaymentMethod = $validated['payment_method'] ?? $oldPaymentMethod;
                
                // Check if payment method actually changed
                if ($oldPaymentMethod !== $newPaymentMethod) {
                    $ledger = \App\Models\FIN\LedgerModel::find($order->ledger_transaction_id);
                    
                    if ($ledger) {
                        // Check if invoice is already settled (with cash — a free order's
                        // auto-settled Rs 0 invoice may still change method: it reposts as Rs 0)
                        if ($ledger->isSettledWithCash()) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot change payment method: Invoice has already been settled.',
                                'error_type' => 'already_settled'
                            ], 422);
                        }
                        
                        // Check if there's a partial settlement
                        if ($ledger->settlement_status === 'partial' || ($ledger->settled_amount > 0)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot change payment method: Invoice has partial settlement.',
                                'error_type' => 'partial_settlement'
                            ], 422);
                        }
                        
                        // RETURNED (Sep-2026): the invoice is frozen — a refund row or
                        // credit grant already stands against it. Reversing + re-posting
                        // it here would move the money a second time.
                        if (app(\App\Services\CRM\OrderReturnService::class)->isLocked((int) $order->id)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Cannot change payment method: ' . \App\Services\CRM\OrderReturnService::LOCK_MESSAGE,
                                'error_type' => 'order_returned'
                            ], 422);
                        }

                        // Payment method can be changed - handle it
                        try {
                            $result = $this->handlePaymentMethodChange($order, $ledger, $newPaymentMethod, $oldPaymentMethod);
                            $paymentMethodChanged = true;
                            $paymentMethodChangeMessage = $result['new_ledger_id']
                                ? "Payment method changed from '{$oldPaymentMethod}' to '{$newPaymentMethod}'. Ledger entry updated."
                                : "Payment method changed from '{$oldPaymentMethod}' to '{$newPaymentMethod}'. Cash invoice reversed — this shop order is now settled from Online Approvals → Shop tab.";
                            
                            \Log::info("Payment method changed successfully", [
                                'order_id' => $order->id,
                                'old_method' => $oldPaymentMethod,
                                'new_method' => $newPaymentMethod,
                                'old_ledger_id' => $result['old_ledger_id'],
                                'new_ledger_id' => $result['new_ledger_id']
                            ]);
                        } catch (\Exception $e) {
                            \Log::error("Failed to handle payment method change", [
                                'order_id' => $order->id,
                                'error' => $e->getMessage()
                            ]);

                            // Oct-19-2026 — the order row was saved with the NEW method
                            // before the ledger step (outside its transaction); the ledger
                            // step rolled back. Put the old method back so the order and
                            // its invoice agree, and a retry is still seen as a change
                            // (KS-36 stayed "Online" with a cash invoice on the rider).
                            try {
                                $order->refresh();
                                $order->update(['payment_method' => $oldPaymentMethod]);
                            } catch (\Throwable $restoreError) {
                                \Log::error("Could not restore payment method after failed change", [
                                    'order_id' => $order->id,
                                    'restore_to' => $oldPaymentMethod,
                                    'error' => $restoreError->getMessage()
                                ]);
                            }

                            return response()->json([
                                'success' => false,
                                'message' => 'Failed to change payment method: ' . $e->getMessage(),
                                'error_type' => 'payment_method_change_failed'
                            ], 500);
                        }
                    }
                }
            }
            
            // Update line items using existing API method
            if (isset($validated['items'])) {
                // Format line items for the existing API method
                $formattedLineItems = [];
                foreach ($validated['items'] as $itemData) {
                    $quantity = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];
                    
                    // Get SKU and variant_id if provided
                    $sku = $itemData['sku'] ?? null;
                    $variantId = $itemData['variant_id'] ?? null;
                    $productId = $itemData['product_id'] ?? null;
                    
                    // If variant_id provided but no product_id, resolve product_id from variant
                    if ($variantId && !$productId) {
                        $variant = \App\Models\CRM\ProductVariantModel::find($variantId);
                        if ($variant) {
                            $productId = $variant->product_id;
                            // Also get SKU from variant if not provided
                            if (!$sku) {
                                $sku = $variant->sku;
                            }
                        }
                    }
                    
                    $isFree = !empty($itemData['is_free']);
                    $updateLineItem = [
                        'name' => $itemData['name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $isFree ? 0 : $quantity * $unitPrice,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'line_total' => $isFree ? 0 : $quantity * $unitPrice,
                        'is_free' => $isFree ? 1 : 0,
                        'sku' => $sku,
                        'variant_id' => $variantId,
                        'product_id' => $productId,
                    ];
                    if (!empty($itemData['qurbani_day'])) $updateLineItem['qurbani_day'] = $itemData['qurbani_day'];
                    if (!empty($itemData['qurbani_slot'])) $updateLineItem['qurbani_slot'] = $itemData['qurbani_slot'];
                    if (!empty($itemData['qurbani_region'])) $updateLineItem['qurbani_region'] = $itemData['qurbani_region'];
                    if (!empty($itemData['qurbani_sub_region'])) $updateLineItem['qurbani_sub_region'] = $itemData['qurbani_sub_region'];
                    if (!empty($itemData['qurbani_delivery_type'])) $updateLineItem['qurbani_delivery_type'] = $itemData['qurbani_delivery_type'];
                    // New qurbani attributes — only written when the form
                    // actually sends a value so older code paths don't
                    // accidentally wipe user edits.
                    if (!empty($itemData['qurbani_type'])) $updateLineItem['qurbani_type'] = $itemData['qurbani_type'];
                    if (!empty($itemData['qurbani_paya'])) $updateLineItem['qurbani_paya'] = $itemData['qurbani_paya'];
                    if (array_key_exists('instructions', $itemData)) $updateLineItem['instructions'] = $itemData['instructions'];

                    // Backward compat: use order-level qurbani fields if item-level not set
                    if (empty($itemData['qurbani_day']) && $request->filled('qurbani_day')) $updateLineItem['qurbani_day'] = $request->qurbani_day;
                    if (empty($itemData['qurbani_slot']) && $request->filled('qurbani_slot')) $updateLineItem['qurbani_slot'] = $request->qurbani_slot;
                    if (empty($itemData['qurbani_region']) && $request->filled('qurbani_region')) $updateLineItem['qurbani_region'] = $request->qurbani_region;
                    if (empty($itemData['qurbani_delivery_type']) && $request->filled('qurbani_delivery_type')) $updateLineItem['qurbani_delivery_type'] = $request->qurbani_delivery_type;

                    $formattedLineItems[] = $updateLineItem;
                }
                
                // Sync qurbani fields from line items to order level (for efficient filtering)
                $firstQurbaniUpdateItem = collect($formattedLineItems)->first(function($item) {
                    return !empty($item['qurbani_day']) || !empty($item['qurbani_slot']) || !empty($item['qurbani_region']) || !empty($item['qurbani_delivery_type']);
                });
                if ($firstQurbaniUpdateItem) {
                    $order->qurbani_day = $firstQurbaniUpdateItem['qurbani_day'] ?? $order->qurbani_day;
                    $order->qurbani_slot = $firstQurbaniUpdateItem['qurbani_slot'] ?? $order->qurbani_slot;
                    $order->qurbani_region = $firstQurbaniUpdateItem['qurbani_region'] ?? $order->qurbani_region;
                    $order->qurbani_sub_region = $firstQurbaniUpdateItem['qurbani_sub_region'] ?? $order->qurbani_sub_region;
                    $order->qurbani_delivery_type = $firstQurbaniUpdateItem['qurbani_delivery_type'] ?? $order->qurbani_delivery_type;
                    $order->save();
                }

                // Update line items directly since this is an existing order
                // ⭐ PRESERVE preparation_status: Get existing line items before deleting
                $existingLineItems = $order->lineItems()->get()->keyBy(function($item) {
                    // Create a key based on product_id + variant_id, or name + sku as fallback
                    if ($item->product_id && $item->variant_id) {
                        return "p{$item->product_id}_v{$item->variant_id}";
                    }
                    return "n_" . md5(($item->name ?? '') . '_' . ($item->sku ?? ''));
                });
                
                // ⭐ INVENTORY: Before deleting old items, restore inventory for any that had it deducted.
                // After creating new items, re-deduct for any that preserved preparation_status='preparing'.
                // This cleanly handles quantity changes, item removals, and item additions.
                $oldItemsWithDeduction = $order->lineItems()
                    ->where('inventory_deducted', 1)
                    ->get();

                // Step 1: Restore inventory for all old deducted items
                foreach ($oldItemsWithDeduction as $oldItem) {
                    $oldItem->restoreInventory();
                }
                
                // Delete existing line items
                $order->lineItems()->delete();
                
                // Create new line items, preserving preparation_status from matching old items
                // NOTE: inventory_deducted is NOT preserved — it will be re-set by deductInventory() below
                $lineItemModels = [];
                foreach ($formattedLineItems as $lineItem) {
                    $lineItem['order_id'] = $order->id;
                    $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                    
                    // ⭐ Try to find matching old item to preserve preparation_status
                    $matchKey = null;
                    if (!empty($lineItem['product_id']) && !empty($lineItem['variant_id'])) {
                        $matchKey = "p{$lineItem['product_id']}_v{$lineItem['variant_id']}";
                    } else {
                        $matchKey = "n_" . md5(($lineItem['name'] ?? '') . '_' . ($lineItem['sku'] ?? ''));
                    }
                    
                    if ($existingLineItems->has($matchKey)) {
                        $oldItem = $existingLineItems->get($matchKey);
                        // Preserve preparation_status from old item (but NOT inventory_deducted)
                        $lineItem['preparation_status'] = $oldItem->preparation_status;

                        // ⭐ Barcode-qty: keep the "barcode vs manual" badge correct across this
                        // delete-and-recreate. If the quantity is UNCHANGED, carry the old source
                        // + audit stamps over (so a barcode-set qty keeps its badge). If the
                        // quantity CHANGED in this save, it's a manual edit -> mark 'manual'
                        // (webhook/sync updates just clear it rather than claim 'manual').
                        $oldQty = (float) ($oldItem->quantity ?? 0);
                        $newQty = (float) ($lineItem['quantity'] ?? 0);
                        if (abs($oldQty - $newQty) < 0.0005) {
                            $lineItem['quantity_source'] = $oldItem->quantity_source;
                            $lineItem['quantity_updated_by'] = $oldItem->quantity_updated_by;
                            $lineItem['quantity_updated_at'] = $oldItem->quantity_updated_at;
                            $lineItem['quantity_scanned_barcode'] = $oldItem->quantity_scanned_barcode;
                        } elseif (!$isWebhookUpdate) {
                            $lineItem['quantity_source'] = 'manual';
                            $lineItem['quantity_updated_by'] = auth()->check() ? auth()->id() : null;
                            $lineItem['quantity_updated_at'] = now();
                            $lineItem['quantity_scanned_barcode'] = null;
                        }
                    }

                    $lineItemModels[] = new \App\Models\CRM\OrderLineItemModel($lineItem);
                }
                
                $order->lineItems()->saveMany($lineItemModels);
                
                // Step 2: Re-deduct inventory for items that should have it deducted.
                // - If order is already out_for_delivery/delivered, ALL items should be deducted
                //   (defensive: covers old orders where preparation_status was never set to 'preparing')
                // - Otherwise, only deduct items with preparation_status='preparing'
                if ($order->order_status !== 'cancelled') {
                    $reDeductedCount = 0;
                    $orderAlreadyPastPrep = in_array($order->order_status, ['out_for_delivery', 'delivered', 'completed']);
                    
                    foreach ($lineItemModels as $model) {
                        if ($orderAlreadyPastPrep) {
                            // Order is already past preparation stage — ensure all items are prepared & deducted
                            if ($model->preparation_status !== 'preparing') {
                                $model->preparation_status = 'preparing';
                                $model->save();
                            }
                            if ($model->deductInventory()) {
                                $reDeductedCount++;
                            }
                        } elseif ($model->preparation_status === 'preparing') {
                            if ($model->deductInventory()) {
                                $reDeductedCount++;
                            }
                        }
                    }
                    if ($reDeductedCount > 0) {
                        \Log::info('Inventory re-deducted after order edit', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'items_re_deducted' => $reDeductedCount,
                            'order_status' => $order->order_status,
                            'forced_by_status' => $orderAlreadyPastPrep,
                        ]);
                    }
                }
            }
            
            // Update discount detail records if provided.
            // ⚠️ NEVER for Shopify STAGING orders: t_crm_order_discounts is keyed by
            // raw order_id and SHARED with production orders, whose auto-increment
            // ids OVERLAP with staging ids. A staging save here would delete /
            // overwrite an UNRELATED production order's discount rows. Staging
            // orders keep their discount at order level (discount_total on the row);
            // conversion reads only that, never this child table.
            if (isset($validated['discounts']) && !($order instanceof \App\Models\CRM\ShopifyOrderModel)) {
                // Delete existing discount records
                $order->discounts()->delete();
                
                // Create new discount records if array is not empty
                if (is_array($validated['discounts']) && !empty($validated['discounts'])) {
                    foreach ($validated['discounts'] as $index => $discountData) {
                        \App\Models\CRM\OrderDiscountModel::create([
                            'order_id' => $order->id,
                            'discount_title' => $discountData['title'],
                            'discount_amount' => $discountData['amount'],
                            'discount_type' => $discountData['type'] ?? 'fixed',
                            'discount_percentage' => $discountData['percentage'] ?? null,
                            'coupon_code' => $discountData['coupon_code'] ?? null,
                            'display_order' => $index,
                            'notes' => $discountData['notes'] ?? null,
                            'created_by' => auth()->check() ? auth()->id() : null
                        ]);
                    }
                    \Log::info('Updated discount details for order', [
                        'order_id' => $order->id,
                        'discount_count' => count($validated['discounts'])
                    ]);
                }
            }
            
            // ⭐ SYNC TO CUSTOMER: Update customer profile if checkbox was checked
            $customerSyncMessage = null;
            if (!$isPartialUpdate && !empty($validated['sync_to_customer']) && $order->customer_id) {
                try {
                    $customer = \App\Models\CRM\CustomerModel::find($order->customer_id);
                    if ($customer) {
                        // Sync name fields
                        if (!empty($validated['address_first_name'])) {
                            $customer->first_name = $validated['address_first_name'];
                        }
                        if (!empty($validated['address_last_name'])) {
                            $customer->last_name = $validated['address_last_name'];
                        }
                        
                        // Sync address fields
                        if (!empty($validated['address_line1'])) {
                            $customer->address1 = $validated['address_line1'];
                        }
                        if (!empty($validated['address_line2'])) {
                            $customer->address2 = $validated['address_line2'];
                        }
                        if (!empty($validated['address_city'])) {
                            $customer->city = $validated['address_city'];
                        }
                        if (!empty($validated['address_province'])) {
                            $customer->province = $validated['address_province'];
                        }
                        if (!empty($validated['address_postal_code'])) {
                            $customer->postal_code = $validated['address_postal_code'];
                        }
                        if (!empty($validated['address_country'])) {
                            $customer->country = $validated['address_country'];
                        }
                        
                        // Sync phone if present on order but not on customer
                        if (!empty($validated['address_phone'])) {
                            $customer->phone_original = $validated['address_phone'];
                        }
                        
                        $customer->updated_by = auth()->id();
                        $customer->save();
                        
                        $customerSyncMessage = 'Customer profile also updated.';
                        \Log::info('Synced order address changes to customer profile', [
                            'order_id' => $order->id,
                            'customer_id' => $customer->id
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to sync address to customer profile', [
                        'order_id' => $order->id,
                        'customer_id' => $order->customer_id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't fail the order update, just log the warning
                }
            }
            
            // Clear cached WhatsApp invoice image so next send uses fresh data.
            // Captures may be .jpg (Jul-2026 JPEG captures) or .png (legacy) —
            // clear both so no stale variant survives the edit.
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            foreach (['png', 'jpg'] as $cachedExt) {
                $cachedPath = 'whatsapp-invoices/Invoice-' . $orderNum . '.' . $cachedExt;
                if (\Storage::disk('public')->exists($cachedPath)) {
                    \Storage::disk('public')->delete($cachedPath);
                }
            }

            // 💵 Tips Fund (Sep-2026) — the edit may have changed the tip.
            //
            // The balance engine collects a tip when the invoice row posts, but
            // an ALREADY-approved invoice is corrected through an adjustment row
            // instead of being re-applied, so the engine never hears about it.
            // This sync is idempotent: it compares what the pool holds for this
            // order against what it should hold and moves only the difference,
            // so it is a no-op on the ordinary save where the tip did not move.
            //
            // ⚠ Never allowed to fail the edit — `tips:backfill` repairs it.
            try {
                app(\App\Services\FIN\TipsFundService::class)
                    ->syncForOrder((int) $order->id, auth()->id());
            } catch (\Throwable $e) {
                \Log::error('Tips Fund: sync after order edit failed', [
                    'order_id' => $order->id, 'error' => $e->getMessage(),
                ]);
            }

            // Prepare response based on whether a ledger adjustment was created or payment method changed
            if ($ledgerAdjustmentCreated) {
                $message = 'Order updated successfully. Ledger adjustment created and pending L1→L2 approval.';
                if ($paymentMethodChanged) {
                    $message .= ' ' . $paymentMethodChangeMessage;
                }
                if ($customerSyncMessage) {
                    $message .= ' ' . $customerSyncMessage;
                }
                if ($statusChangeWarning) {
                    $message .= ' ⚠️ ' . $statusChangeWarning;
                }
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    // ⚠ The status did NOT change (van custody, a refused
                    //   transition, a failed invoice post). It rides inside
                    //   `message` too, but as its own field the page can be sure
                    //   to SHOW it instead of printing a generic success.
                    'status_warning' => $statusChangeWarning ?: null,
                    'requires_approval' => true,
                    'adjustment_id' => $adjustmentId,
                    'payment_method_changed' => $paymentMethodChanged,
                    'customer_synced' => !empty($customerSyncMessage),
                    'order' => $order->load(['customer', 'lineItems', 'discounts'])
                ]);
            } else {
                $message = 'Order updated successfully!';
                if ($paymentMethodChanged) {
                    $message = $paymentMethodChangeMessage;
                }
                if ($customerSyncMessage) {
                    $message .= ' ' . $customerSyncMessage;
                }
                if ($statusChangeWarning) {
                    $message .= ' ⚠️ ' . $statusChangeWarning;
                }
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    // See above — the change the user asked for was refused.
                    'status_warning' => $statusChangeWarning ?: null,
                    'payment_method_changed' => $paymentMethodChanged,
                    'customer_synced' => !empty($customerSyncMessage),
                    'order' => $order->load(['customer', 'lineItems', 'discounts'])
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function store(Request $request)
    {
        // Check permission. The mobile app creates orders via POST /api/orders and is
        // gated by the MOBILE 'create_orders' permission; the web screen keeps the WEB
        // permission. The transitional OR keeps web-granted roles working before the
        // matching mobile grant is seeded (Phase 0.7 SQL). The web path is unchanged.
        $user = auth()->user();
        $canCreateOrders = $request->is('api/*')
            ? ($user->hasMobilePermission('create_orders') || $user->hasPermission('create_orders'))
            : $user->hasPermission('create_orders');
        if (!$canCreateOrders) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to create orders.'
            ], 403);
        }
        
        try {
            // Validate request
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:t_crm_prod_customer,id',
                // Allow null to auto-default to 'new' server-side
                'order_status' => 'nullable|string|exists:t_crm_order_status_master,status_code',
                'order_date' => 'required|date',
                'contact_email' => 'nullable|email',
                'subtotal_price' => 'required|numeric',
                'discount_total' => 'nullable|numeric',
                'shipping_total' => 'nullable|numeric',
                'tip_amount' => 'nullable|numeric|min:0',
                'total_price' => 'required|numeric',
                'coupon_code' => 'nullable|string',
                'payment_method' => 'nullable|string',
                // Aug-2026 — the "Set as default for this customer" tick next to
                // the payment picker on BOTH order forms. Not an order column;
                // stripped out below before the order is built.
                'set_default_payment_method' => 'nullable|boolean',
                'note' => 'nullable|string',
                'items' => 'required|array',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.line_total' => 'required|numeric|min:0',
                'items.*.sku' => 'nullable|string',
                'items.*.variant_id' => 'nullable|string',
                'items.*.product_id' => 'nullable|string',
                'items.*.is_free' => 'nullable|boolean',
                'items.*.qurbani_day' => 'nullable|string|max:50',
                'items.*.qurbani_slot' => 'nullable|string|max:50',
                'items.*.qurbani_region' => 'nullable|string|max:100',
                'items.*.qurbani_sub_region' => 'nullable|string|max:100',
                'items.*.qurbani_delivery_type' => 'nullable|string|max:50',
                // New Apr-2026 qurbani attributes (configurable dropdowns).
                'items.*.qurbani_type' => 'nullable|string|max:50',
                'items.*.qurbani_paya' => 'nullable|string|max:50',
                'items.*.instructions' => 'nullable|string|max:500',
                // Customer creation fields
                'customer_phone' => 'nullable|string',
                'customer_first_name' => 'nullable|string',
                'customer_last_name' => 'nullable|string',
                'customer_company' => 'nullable|string',
                'customer_address1' => 'nullable|string',
                'customer_address2' => 'nullable|string',
                'customer_city' => 'nullable|string',
                'customer_province' => 'nullable|string',
                'customer_postal_code' => 'nullable|string',
                'customer_country' => 'nullable|string',
                // Multiple discounts support (NEW - optional, backward compatible)
                'discounts' => 'nullable|array',
                'discounts.*.title' => 'required_with:discounts|string|max:255',
                'discounts.*.amount' => 'required_with:discounts|numeric|min:0',
                'discounts.*.type' => 'nullable|in:fixed,percentage',
                'discounts.*.percentage' => 'nullable|numeric|min:0|max:100',
                'discounts.*.coupon_code' => 'nullable|string|max:100',
                'discounts.*.notes' => 'nullable|string',
                'qurbani_day' => 'nullable|string|max:50',
                'qurbani_slot' => 'nullable|string|max:50',
                'qurbani_region' => 'nullable|string|max:100',
                'qurbani_delivery_type' => 'nullable|string|max:50',
            ]);

            // ⭐ Customer credit: the ACCOUNT_BALANCE line is server-owned money —
            // a brand-new order can never arrive with one (credit is applied via
            // /orders/{id}/credit/apply AFTER the order exists), so any such line
            // in a create payload is fabricated. Dropped HERE, before the total
            // recalculation below, so the totals never include it either.
            if (isset($validated['discounts']) && is_array($validated['discounts'])) {
                $validated['discounts'] = array_values(array_filter($validated['discounts'], function ($d) {
                    return ($d['coupon_code'] ?? null) !== \App\Models\CRM\CustomerCreditModel::DISCOUNT_CODE
                        && trim((string) ($d['title'] ?? '')) !== 'Account balance applied';
                }));
            }

            // 📦 Bundled products (Oct-2026): a line that still names a BUNDLE (an older
            // phone app that doesn't know bundles) becomes one line per product inside, at
            // the packet prices entered on the bundle — the same rule as Shopify conversion
            // (App\Services\CRM\ProductBundle). The current web and phone forms already
            // send the products inside, which can never be bundles, so nothing is expanded
            // twice. Never throws: a broken bundle line is saved as it came (at the bundle
            // total). Runs BEFORE the totals below so they see the real lines.
            if (!empty($validated['items'])) {
                $validated['items'] = \App\Services\CRM\ProductBundle::expandFormItems($validated['items']);
            }

            // ================================================================
            // ⭐ SERVER-SIDE TOTAL RECALCULATION (Feb 2026)
            // Always recalculate totals from line items to prevent frontend/backend mismatches
            // ================================================================
            if (isset($validated['items']) && is_array($validated['items']) && !empty($validated['items'])) {
                // ⭐ Enforce is_free: if item is marked free, force line_total to 0
                foreach ($validated['items'] as &$storeItemRef) {
                    if (!empty($storeItemRef['is_free'])) {
                        $storeItemRef['line_total'] = 0;
                    }
                }
                unset($storeItemRef);
                
                // Calculate subtotal from line items (free items contribute 0)
                $calculatedSubtotal = collect($validated['items'])->sum(function($item) {
                    if (!empty($item['is_free'])) {
                        return 0;
                    }
                    return floatval($item['line_total'] ?? ($item['quantity'] * $item['unit_price']));
                });
                
                // Calculate discount total from discounts array (if provided)
                $calculatedDiscountTotal = 0;
                if (isset($validated['discounts']) && is_array($validated['discounts'])) {
                    $calculatedDiscountTotal = collect($validated['discounts'])->sum('amount');
                } elseif (isset($validated['discount_total'])) {
                    $calculatedDiscountTotal = floatval($validated['discount_total']);
                }
                
                // Calculate expected total
                $shipping = floatval($validated['shipping_total'] ?? 0);
                $tip = floatval($validated['tip_amount'] ?? 0);
                $calculatedTotal = $calculatedSubtotal - $calculatedDiscountTotal + $shipping + $tip;
                
                // Log if there's a mismatch (for debugging)
                $frontendSubtotal = floatval($validated['subtotal_price'] ?? 0);
                $frontendTotal = floatval($validated['total_price'] ?? 0);
                
                if (abs($frontendSubtotal - $calculatedSubtotal) > 1 || abs($frontendTotal - $calculatedTotal) > 1) {
                    \Log::warning('New order total mismatch detected - using server-calculated values', [
                        'frontend_subtotal' => $frontendSubtotal,
                        'calculated_subtotal' => $calculatedSubtotal,
                        'frontend_total' => $frontendTotal,
                        'calculated_total' => $calculatedTotal,
                        'discount' => $calculatedDiscountTotal,
                        'shipping' => $shipping,
                        'tip' => $tip,
                    ]);
                }
                
                // Always use server-calculated values
                $validated['subtotal_price'] = $calculatedSubtotal;
                $validated['total_price'] = $calculatedTotal;
            }
            
            // ⭐ Calculate discount_total from discounts array
            // If discounts array is provided (even if empty), update discount_total
            if (isset($validated['discounts']) && is_array($validated['discounts'])) {
                if (!empty($validated['discounts'])) {
                    $calculatedDiscountTotal = collect($validated['discounts'])->sum('amount');
                    $validated['discount_total'] = $calculatedDiscountTotal;
                    \Log::info('Multiple discounts provided', [
                        'count' => count($validated['discounts']),
                        'calculated_total' => $calculatedDiscountTotal
                    ]);
                } else {
                    // Empty discounts array
                    $validated['discount_total'] = 0;
                }
            }
            
            // Default order status to 'new' if not provided
            if (empty($validated['order_status'])) {
                $validated['order_status'] = 'new';
            }

            // Qurbani / regular order mixing guard:
            // Resolve actual product IDs from line items (handles variant_XXX format)
            $lineProductIds = collect($validated['items'] ?? [])
                ->map(function ($item) {
                    // Try product_id first, then variant_id
                    $pid = $item['product_id'] ?? null;
                    $vid = $item['variant_id'] ?? null;

                    // Resolve variant_XXX format from either field
                    foreach ([$pid, $vid] as $idVal) {
                        if ($idVal && str_starts_with((string)$idVal, 'variant_')) {
                            $variantId = (int) str_replace('variant_', '', $idVal);
                            $resolved = \DB::table('t_crm_prod_product_variant')
                                ->where('id', $variantId)->value('product_id');
                            if ($resolved) return (int) $resolved;
                        }
                    }

                    // Numeric variant_id → resolve to product_id
                    if ($vid && is_numeric($vid)) {
                        $resolved = \DB::table('t_crm_prod_product_variant')
                            ->where('id', (int)$vid)->value('product_id');
                        if ($resolved) return (int) $resolved;
                    }

                    // Direct product_id
                    if ($pid && is_numeric($pid)) {
                        return (int) $pid;
                    }

                    // Fallback: try SKU lookup
                    $sku = $item['sku'] ?? null;
                    if ($sku) {
                        $resolved = \DB::table('t_crm_prod_product_variant')
                            ->where('sku', $sku)->value('product_id');
                        if ($resolved) return (int) $resolved;
                    }

                    return null;
                })
                ->filter()
                ->unique()
                ->values();

            if ($lineProductIds->isNotEmpty()) {
                $qurbaniCount = \DB::table('t_crm_prod_product')
                    ->whereIn('id', $lineProductIds)
                    ->whereRaw("LOWER(attribute_1) = 'qurbani'")
                    ->count();
                $totalCount = $lineProductIds->count();

                if ($qurbaniCount > 0 && $qurbaniCount < $totalCount) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot mix Qurbani and regular products in one order. Please create separate orders for Qurbani items.'
                    ], 422);
                }

                $isQurbaniOrder = $qurbaniCount > 0 && $qurbaniCount === $totalCount;
            } else {
                $isQurbaniOrder = false;
            }

            // Validate qurbani fields are present for qurbani orders
            if ($isQurbaniOrder) {
                $validated['qurbani_day'] = $request->input('qurbani_day');
                $validated['qurbani_slot'] = $request->input('qurbani_slot');
                $validated['qurbani_region'] = $request->input('qurbani_region');
                $validated['qurbani_delivery_type'] = $request->input('qurbani_delivery_type');
            }

            // Generate order number
            if ($isQurbaniOrder) {
                // QUR format: QUR26-001 (2-digit year + sequential 3-digit number, resets each year)
                $orderNumber = \DB::transaction(function() {
                    $yearSuffix = date('y');
                    $prefix = 'QUR' . $yearSuffix . '-';
                    $prefixLen = strlen($prefix) + 1;
                    $maxSeq1 = \DB::table('t_crm_prod_order')
                        ->where('order_number', 'LIKE', $prefix . '%')
                        ->lockForUpdate()
                        ->max(\DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                    $maxSeq2 = \DB::table('t_crm_shopify_order')
                        ->where('order_number', 'LIKE', $prefix . '%')
                        ->lockForUpdate()
                        ->max(\DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                    $nextSeq = max(($maxSeq1 ?? 0), ($maxSeq2 ?? 0)) + 1;
                    return $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
                });
            } else {
                $orderNumber = \DB::transaction(function() {
                    $maxOrderNumber = \DB::table('t_crm_prod_order')
                        ->where('order_number', 'LIKE', 'NF-%')
                        ->lockForUpdate()
                        ->max(\DB::raw("CAST(SUBSTRING(order_number, 4) AS UNSIGNED)"));
                    $nextNumber = ($maxOrderNumber ?? 0) + 1;
                    return 'NF-' . $nextNumber;
                });
            }
            
            // Handle customer selection/population
            $customerId = $validated['customer_id'] ?? null;
            if (!$customerId && !empty($validated['customer_phone'])) {
                // Don't create customer here - let storeOrderFromApi handle it to avoid double counting
                // Just populate address fields for the order
                $validated['address_first_name'] = $validated['customer_first_name'] ?? null;
                $validated['address_last_name'] = $validated['customer_last_name'] ?? null;
                $validated['address_company'] = $validated['customer_company'] ?? null;
                $validated['address_email'] = $validated['contact_email'] ?? null;
                $validated['address_phone'] = $validated['customer_phone'] ?? null;
                $validated['address_line1'] = $validated['customer_address1'] ?? null;
                $validated['address_line2'] = $validated['customer_address2'] ?? null;
                $validated['address_city'] = $validated['customer_city'] ?? null;
                $validated['address_province'] = $validated['customer_province'] ?? null;
                $validated['address_postal_code'] = $validated['customer_postal_code'] ?? null;
                $validated['address_country'] = $validated['customer_country'] ?? 'Pakistan';
            } elseif ($customerId) {
                // Load existing customer and populate address fields
                $customer = \App\Models\CRM\CustomerModel::find($customerId);
                if ($customer) {
                    $validated['address_first_name'] = $customer->first_name;
                    $validated['address_last_name'] = $customer->last_name;
                    $validated['address_company'] = $customer->company;
                    $validated['address_email'] = $customer->email;
                    $validated['address_phone'] = $customer->phone_original;
                    $validated['address_line1'] = $customer->address1;
                    $validated['address_line2'] = $customer->address2;
                    $validated['address_city'] = $customer->city;
                    $validated['address_province'] = $customer->province;
                    $validated['address_postal_code'] = $customer->postal_code;
                    $validated['address_country'] = $customer->country;
                    
                    // Update customer KPIs for webapp orders
                    $customer->recalculateStatistics();
                }
            }
            
            // Create order
            $orderData = array_merge($validated, [
                'customer_id' => $customerId, // Will be null for new customers, storeOrderFromApi will handle it
                'external_source' => 'webapp',
                'order_number' => $orderNumber,
                'currency' => 'PKR',
                'name' => trim(($validated['address_first_name'] ?? '') . ' ' . ($validated['address_last_name'] ?? '')), // Populate name from address
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);
            
            // Remove customer creation fields from order data.
            // ⚠ set_default_payment_method belongs on the CUSTOMER, not the order —
            // it must be stripped here or it rides array_merge($validated, ...) all
            // the way into storeOrderFromApi() as a phantom order column.
            $customerFields = ['customer_phone', 'customer_first_name', 'customer_last_name', 'customer_company', 'customer_address1', 'customer_address2', 'customer_city', 'customer_province', 'customer_postal_code', 'customer_country', 'set_default_payment_method'];
            foreach ($customerFields as $field) {
                unset($orderData[$field]);
            }
            
            // Format line items for the existing API method
            $formattedLineItems = [];
            if (isset($validated['items'])) {
                foreach ($validated['items'] as $itemData) {
                    $quantity = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];
                    
                    // Get SKU and variant_id if provided
                    $sku = $itemData['sku'] ?? null;
                    $variantId = $itemData['variant_id'] ?? null;
                    $productId = $itemData['product_id'] ?? null;
                    
                    // If variant_id provided but no product_id, resolve product_id from variant
                    if ($variantId && !$productId) {
                        $variant = \App\Models\CRM\ProductVariantModel::find($variantId);
                        if ($variant) {
                            $productId = $variant->product_id;
                            // Also get SKU from variant if not provided
                            if (!$sku) {
                                $sku = $variant->sku;
                            }
                        }
                    }
                    
                    $isFreeStore = !empty($itemData['is_free']);
                    $lineItemData = [
                        'name' => $itemData['name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $isFreeStore ? 0 : $quantity * $unitPrice,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'line_total' => $isFreeStore ? 0 : $quantity * $unitPrice,
                        'is_free' => $isFreeStore ? 1 : 0,
                        'sku' => $sku,
                        'variant_id' => $variantId,
                        'product_id' => $productId,
                    ];
                    if (!empty($itemData['qurbani_day'])) $lineItemData['qurbani_day'] = $itemData['qurbani_day'];
                    if (!empty($itemData['qurbani_slot'])) $lineItemData['qurbani_slot'] = $itemData['qurbani_slot'];
                    if (!empty($itemData['qurbani_region'])) $lineItemData['qurbani_region'] = $itemData['qurbani_region'];
                    if (!empty($itemData['qurbani_sub_region'])) $lineItemData['qurbani_sub_region'] = $itemData['qurbani_sub_region'];
                    if (!empty($itemData['qurbani_delivery_type'])) $lineItemData['qurbani_delivery_type'] = $itemData['qurbani_delivery_type'];
                    if (!empty($itemData['qurbani_type'])) $lineItemData['qurbani_type'] = $itemData['qurbani_type'];
                    if (!empty($itemData['qurbani_paya'])) $lineItemData['qurbani_paya'] = $itemData['qurbani_paya'];
                    if (array_key_exists('instructions', $itemData)) $lineItemData['instructions'] = $itemData['instructions'];

                    // Backward compat: also accept order-level qurbani fields and apply to all items
                    if (empty($itemData['qurbani_day']) && !empty($validated['qurbani_day'])) $lineItemData['qurbani_day'] = $validated['qurbani_day'];
                    if (empty($itemData['qurbani_slot']) && !empty($validated['qurbani_slot'])) $lineItemData['qurbani_slot'] = $validated['qurbani_slot'];
                    if (empty($itemData['qurbani_region']) && !empty($validated['qurbani_region'])) $lineItemData['qurbani_region'] = $validated['qurbani_region'];
                    if (empty($itemData['qurbani_delivery_type']) && !empty($validated['qurbani_delivery_type'])) $lineItemData['qurbani_delivery_type'] = $validated['qurbani_delivery_type'];

                    $formattedLineItems[] = $lineItemData;
                }
            }
            
            // Add line items to order data and use existing storeOrderFromApi method
            $orderData['line_items'] = $formattedLineItems;
            
            // Use existing storeOrderFromApi method to handle both order and line items
            $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
            
            // Sync qurbani fields from line items to order level (for efficient filtering)
            $firstQurbaniItem = collect($formattedLineItems)->first(function($item) {
                return !empty($item['qurbani_day']) || !empty($item['qurbani_slot']) || !empty($item['qurbani_region']) || !empty($item['qurbani_delivery_type']);
            });
            if ($firstQurbaniItem) {
                $order->update([
                    'qurbani_day' => $firstQurbaniItem['qurbani_day'] ?? $order->qurbani_day,
                    'qurbani_slot' => $firstQurbaniItem['qurbani_slot'] ?? $order->qurbani_slot,
                    'qurbani_region' => $firstQurbaniItem['qurbani_region'] ?? $order->qurbani_region,
                    'qurbani_sub_region' => $firstQurbaniItem['qurbani_sub_region'] ?? $order->qurbani_sub_region,
                    'qurbani_delivery_type' => $firstQurbaniItem['qurbani_delivery_type'] ?? $order->qurbani_delivery_type,
                ]);
            }
            
            // Create discount detail records if multiple discounts were provided.
            // (Any fabricated ACCOUNT_BALANCE line was already stripped above,
            // before the totals were computed.)
            if (isset($validated['discounts']) && is_array($validated['discounts']) && !empty($validated['discounts'])) {
                foreach ($validated['discounts'] as $index => $discountData) {
                    \App\Models\CRM\OrderDiscountModel::create([
                        'order_id' => $order->id,
                        'discount_title' => $discountData['title'],
                        'discount_amount' => $discountData['amount'],
                        'discount_type' => $discountData['type'] ?? 'fixed',
                        'discount_percentage' => $discountData['percentage'] ?? null,
                        'coupon_code' => $discountData['coupon_code'] ?? null,
                        'display_order' => $index,
                        'notes' => $discountData['notes'] ?? null,
                        'created_by' => auth()->id()
                    ]);
                }
                \Log::info('Created discount details for order', [
                    'order_id' => $order->id,
                    'discount_count' => count($validated['discounts'])
                ]);
            }
            
            // Aug-2026 — "Set as default for this customer" tick next to the
            // payment picker (web Create-Order modal + mobile New Order sheet).
            //
            // Written from $order->customer_id rather than the request's
            // customer_id on purpose: on the create-a-new-customer path the
            // request has no id at all and storeOrderFromApi() is what resolves
            // (or creates) the customer, so this is the only value that is
            // right in BOTH cases.
            //
            // Normalized down to 'cash'/'online' so the two forms' different
            // stored values ('cash_on_delivery' vs 'cash') land as one choice.
            // Non-fatal by design: remembering a preference must never be the
            // reason an order fails to save.
            if ($order->customer_id
                && filter_var($request->input('set_default_payment_method', false), FILTER_VALIDATE_BOOLEAN)) {
                try {
                    // ONE writer for value + attribution — see CustomerModel::setDefaultPaymentMethod.
                    $defaultPm = \App\Models\CRM\CustomerModel::setDefaultPaymentMethod(
                        $order->customer_id,
                        $validated['payment_method'] ?? null,
                        auth()->id()
                    );
                    if ($defaultPm !== null && $defaultPm !== false) {
                        \Log::info('Customer default payment method set from order create', [
                            'customer_id' => $order->customer_id,
                            'order_id' => $order->id,
                            'default_payment_method' => $defaultPm,
                            'user_id' => auth()->id(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Failed to set customer default payment method (non-fatal)', [
                        'customer_id' => $order->customer_id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // ⭐ AUTO-GEOCODE: give this stop a location so dispatch can time it.
            // Note: geocoded_latitude/longitude is separate from latitude/longitude (verified location)
            //
            // Keyed on "has no VERIFIED pin" (not "has no geocode"), so it also
            // REFRESHES a pin left by the old Nominatim engine — those are worth
            // replacing: 1,611 customers were sitting on three city centroids, and
            // a stop timed to the middle of Islamabad looks perfectly healthy while
            // being kilometres wrong. Self-healing by design: every customer who
            // still lacks a human pin gets Google's best answer on their next order,
            // so no historical correction run is ever needed. Customers who DO have
            // a verified pin are skipped — that pin wins everywhere downstream, so
            // the call would be wasted work.
            if ($order->customer_id) {
                $customer = \App\Models\CRM\CustomerModel::find($order->customer_id);
                if ($customer && \App\Services\GeocodingService::needsGeocodeRefresh($customer)) {
                    // Geocode in background (don't block order creation)
                    try {
                        // force=true only when stale coords already exist, since
                        // geocodeCustomer() returns early on a populated slot.
                        \App\Services\GeocodingService::geocodeCustomer(
                            $order->customer_id,
                            (bool) ($customer->geocoded_latitude && $customer->geocoded_longitude),
                            ['trigger' => 'order_create', 'order_id' => $order->id, 'user_id' => auth()->id()]
                        );
                        \Log::info('Auto-geocoding triggered for new order', [
                            'order_id' => $order->id,
                            'customer_id' => $order->customer_id
                        ]);
                    } catch (\Exception $e) {
                        \Log::warning('Auto-geocoding failed for customer', [
                            'customer_id' => $order->customer_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // Auto-detect delivery region for new order
            if ($order->customer_id || $order->id) {
                try {
                    \App\Services\RegionDetectionService::detectAndSaveForOrder($order->id, $order->customer_id);
                } catch (\Exception $e) {
                    \Log::warning('Auto region detection failed for new order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Auto location request: queue this customer if the automation is ON and
            // they qualify (no-op when the switch is off). Never blocks order creation.
            if ($order->customer_id) {
                try {
                    $locSvc = app(\App\Services\Location\OpenOrderLocationService::class);
                    $locSvc->enqueue((int) $order->customer_id, (int) $order->id, 'nf_create');
                    // Inside the window this sends instantly (drain fires off-request,
                    // never blocks); outside it no-ops and the row waits for the morning.
                    $locSvc->fireFromRequest();
                } catch (\Throwable $e) {
                    \Log::warning('Auto location-request enqueue failed for new order', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'order' => $order->load(['customer', 'lineItems', 'discounts'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function importOrders(Request $request)
    {
        try {
            $validated = $request->validate([
                'source' => 'required',
                'from_date' => 'required|date',
                'to_date'   => 'required|date|after_or_equal:from_date',
            ]);
            
            $orderCount = 0;
            
            if ($validated['source'] === "Shopify") {
                $orderCount = $this->importShopify($validated);
            } else if ($validated['source'] === "WooCommerce") {
                $orderCount = $this->importWooOrders($validated);
            }
            
            if ($orderCount === 0) {
                return redirect()->back()->with('warning', 'No orders found for the selected date range in ' . $validated['source'] . '.');
            }
            
            return redirect()->back()->with('success', $orderCount . ' ' . $validated['source'] . ' orders imported successfully to approval queue.');
            
        } catch (\Exception $e) {
            \Log::error('Order import failed', [
                'source' => $request->input('source'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }


    private function importWooOrders($validated)
    {

        $allOrders = $this->wooCommerce->fetchOrders($validated['from_date'], $validated['to_date']);
        $orderModel = new OrderModel();
        $importedCount = 0;
        foreach ($allOrders as $wooOrder) {
            try {
                // Map WooCommerce order to our format
                $orderData = \App\Models\CRM\OrderModel::mapWooCommerceOrder($wooOrder);
                
                // Store order with line items and customer management
                \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
                $importedCount++;
            } catch (\Exception $innerEx) {
                \Log::error("Failed to process WooCommerce order ID {$wooOrder['id']}: " . $innerEx->getMessage());
                // Continue to next order
                continue;
            }
        }
        return $importedCount;
    }

    private function importShopify($validated)
    {
        try {
            \Log::info('Starting Shopify import', [
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'user_id' => auth()->id()
            ]);

            // ✅ Step 1: Fetch orders from Shopify Service
            $orders = $this->shopify->fetchOrders($validated['from_date'], $validated['to_date']);

            \Log::info('Shopify orders fetched', [
                'count' => count($orders),
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date']
            ]);

            if (empty($orders)) {
                \Log::warning('No Shopify orders found for date range', [
                    'from_date' => $validated['from_date'],
                    'to_date' => $validated['to_date']
                ]);
                return 0; // Return 0 count, not a redirect (caller handles that)
            }

            // Store orders in new DB structure (ShopifyOrderModel - approval queue)
            $importedCount = 0;
            $errorCount = 0;
            foreach ($orders as $shopifyOrder) {
                try {
                    // Map Shopify order to our format
                    $orderData = \App\Models\CRM\OrderModel::mapShopifyOrder($shopifyOrder);
                    
                    // Store order with line items and customer management
                    // Note: storeOrderFromApi routes shopify orders to ShopifyOrderModel automatically
                    \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
                    $importedCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    \Log::error('Failed to import Shopify order: ' . $e->getMessage(), [
                        'shopify_order_id' => $shopifyOrder['id'] ?? 'unknown',
                        'order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Continue with next order instead of failing completely
                }
            }

            \Log::info('Shopify import completed', [
                'imported' => $importedCount,
                'errors' => $errorCount,
                'total_fetched' => count($orders)
            ]);

            // Return success count
            return $importedCount;
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Shopify import validation error', [
                'errors' => $e->errors(),
                'user_id' => auth()->id()
            ]);
            throw $e; // Re-throw to let Laravel handle validation errors
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Shopify API request failure
            \Log::error('Shopify API connection error', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);
            throw new \Exception('Failed to connect to Shopify API: ' . $e->getMessage());
            
        } catch (\Exception $e) {
            // Catch-all for unexpected errors
            \Log::error('Shopify Import Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id()
            ]);
            throw $e; // Re-throw to show proper error message
        }
    }



    function list(Request $request)
    {
        try {
            $response = $this->orderModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function getdetail($id)
    {

        try {
            $response = $this->orderModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function get($id)
    {
        try {
            $response = $this->orderModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    public function convertOrder(Request $request, $id)
    {
        try {
            // Find the original Shopify order in the new Shopify table
            $originalOrder = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems'])
                ->findOrFail($id);
            
            // Check if already converted or ignored
            if ($originalOrder->converted) {
                $status = $originalOrder->converted == 1 ? 'converted' : 'ignored';
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }

            // Delivery-promise (Jul-2026): the accept button the staff pressed decides
            // BOTH the customer confirmation message and whether the order is active
            // today ('new') or parked for a future day ('pending'). Empty / unknown =
            // plain accept with no message (the original convert behaviour). The day
            // logic lives in the UI (which buttons show); the server just maps the
            // chosen promise deterministically.
            $promise = strtolower(trim((string) $request->input('delivery_promise', '')));
            $promiseMap = [
                'today'     => ['status' => 'new',     'template' => 'deliver_today'],
                'tomorrow'  => ['status' => 'pending', 'template' => 'deliver_tomorrow'],
                'wednesday' => ['status' => 'pending', 'template' => 'deliver_wednesday'],
                'thursday'  => ['status' => 'pending', 'template' => 'deliver_thursday'],
            ];
            $promiseCfg    = $promiseMap[$promise] ?? null; // null = accept, no message
            $parkedPending = false; // set true when the order is held for a future day

            // Validate SKUs and recalculate prices
            $validationResult = $this->validateAndRecalculateOrder($originalOrder);
            if (!$validationResult['success']) {
                return response()->json($validationResult, 400);
            }
            
            // Prepare order data for conversion
            $orderData = $originalOrder->toArray();
            
            // Remove fields that should not be duplicated
            unset($orderData['id']);
            unset($orderData['created_at']);
            unset($orderData['updated_at']);
            unset($orderData['converted']);
            
            // Set appropriate source and order number based on original source
            $isKhaasStorage = $originalOrder->external_source === 'khaas_storage';
            $orderData['external_source'] = $isKhaasStorage ? 'khaas_storage' : 'webapp';
            $orderData['external_id'] = null;
            $orderData['external_customer_id'] = null;
            
            // Determine order number: QUR for qurbani items, SH- for regular Shopify, keep for khaas_storage
            if ($isKhaasStorage) {
                $orderData['order_number'] = $originalOrder->order_number;
            } else {
                // Check if any recalculated line item is a qurbani product
                $lineItemProductIds = collect($validationResult['recalculated_line_items'])
                    ->pluck('product_id')->filter()->unique();
                $hasQurbani = $lineItemProductIds->isNotEmpty() && \DB::table('t_crm_prod_product')
                    ->whereIn('id', $lineItemProductIds)
                    ->whereRaw("LOWER(attribute_1) = 'qurbani'")
                    ->exists();

                if ($hasQurbani) {
                    $orderData['order_number'] = \DB::transaction(function() {
                        $yearSuffix = date('y');
                        $prefix = 'QUR' . $yearSuffix . '-';
                        $prefixLen = strlen($prefix) + 1;
                        $maxSeq1 = \DB::table('t_crm_prod_order')
                            ->where('order_number', 'LIKE', $prefix . '%')
                            ->lockForUpdate()
                            ->max(\DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                        $maxSeq2 = \DB::table('t_crm_shopify_order')
                            ->where('order_number', 'LIKE', $prefix . '%')
                            ->lockForUpdate()
                            ->max(\DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                        $nextSeq = max(($maxSeq1 ?? 0), ($maxSeq2 ?? 0)) + 1;
                        return $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
                    });
                } else {
                    $orderData['order_number'] = 'SH-' . $originalOrder->order_number;
                }
            }

            // Force creation in t_crm_prod_order (bypass approval queue routing)
            $orderData['_force_prod_order'] = true;
            
            // Set current timestamp for order date
            $orderData['order_date'] = now();
            
            // Use recalculated line items and totals
            $orderData['line_items'] = $validationResult['recalculated_line_items'];
            $orderData['subtotal_price'] = $validationResult['new_subtotal'];
            $orderData['total_price'] = $validationResult['new_total'];

            // Ensure new converted webapp invoices start in 'new' status (non-Shopify orders only)
            // This preserves existing functionality while initializing the status system correctly.
            $orderData['order_status'] = $orderData['order_status'] ?? 'new';
            
            // Use existing storeOrderFromApi method to create the converted order
            $convertedOrder = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);

            // Force final status to 'new' AFTER creation so any mapped legacy status from Shopify
            // cannot overwrite it inside store logic. This preserves the request to always start
            // converted invoices in 'new' while keeping all other conversion behavior intact.
            try {
                if (method_exists($convertedOrder, 'changeStatus')) {
                    $convertedOrder->changeStatus('new', 'Converted from Shopify approval');
                } else {
                    $convertedOrder->order_status = 'new';
                    $convertedOrder->save();
                }
            } catch (\Throwable $e) {
                \Log::warning('Unable to set converted order status to new', [
                    'order_id' => $convertedOrder->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            // Apply the delivery-promise (regular orders only — qurbani + khaas
            // storage have their own flows and never show these buttons).
            // $promiseButtonsApply = "this order type OFFERS the buttons at all" —
            // kept separate from $promiseApplies ("a promise was actually chosen")
            // because the audit row below has to tell those two apart.
            $promiseButtonsApply = !$isKhaasStorage && !(isset($hasQurbani) && $hasQurbani);
            $promiseApplies      = $promiseCfg !== null && $promiseButtonsApply;
            $promiseMessageSent  = false;
            if ($promiseApplies) {
                // Future-day promise → park in 'pending' (held out of today's active
                // pipeline; the team activates it to 'new' on its delivery day, which
                // then fires the location request — Option B). 'today' stays 'new'.
                if ($promiseCfg['status'] === 'pending') {
                    try {
                        $convertedOrder->changeStatus('pending', 'Accepted for future delivery (' . $promise . ')');
                        $parkedPending = true;
                    } catch (\Throwable $e) {
                        \Log::warning('Failed to park converted order as pending', [
                            'order_id' => $convertedOrder->id ?? null, 'error' => $e->getMessage(),
                        ]);
                    }
                }
                // Delivery confirmation FIRST (location request, if any, follows below).
                if (!empty($promiseCfg['template'])) {
                    $promiseMessageSent = $this->sendDeliveryPromiseMessage($convertedOrder, $promiseCfg['template']);
                }
            }

            // Aug-2026 — record the MESSAGING decision on the order's Activity Log.
            // "Accept without messaging" previously left no trace anywhere: SH-21707 was
            // accepted that way on Aug-5 and the only way to prove it afterwards was to
            // spot a MISSING status-history note and an empty WhatsApp thread. One audit
            // row per acceptance makes it answerable in a single look — and a send that
            // fails at the API now says so instead of dying in a log nobody reads.
            // Skipped for khaas/qurbani: those flows never offer the buttons, so a
            // "no message" row there would be noise rather than signal.
            if ($promiseButtonsApply) {
                $templateWanted = $promiseApplies && !empty($promiseCfg['template']);
                $orderLabel     = (string) ($convertedOrder->order_number ?? ('#' . $convertedOrder->id));

                if (!$templateWanted) {
                    \App\Services\AuditLogger::log(
                        'accepted_no_message', 'order', $convertedOrder->id, $orderLabel, null,
                        (int) $convertedOrder->id,
                        $promiseCfg === null
                            ? 'Accepted without messaging — no WhatsApp confirmation sent to the customer.'
                            : 'Accepted (' . $promise . ') — no WhatsApp confirmation sent to the customer.'
                    );
                } elseif ($promiseMessageSent) {
                    \App\Services\AuditLogger::log(
                        'accepted_with_message', 'order', $convertedOrder->id, $orderLabel, null,
                        (int) $convertedOrder->id,
                        'Accepted (' . $promise . ') — "' . $promiseCfg['template'] . '" confirmation sent.'
                    );
                } else {
                    \App\Services\AuditLogger::log(
                        'accepted_message_failed', 'order', $convertedOrder->id, $orderLabel, null,
                        (int) $convertedOrder->id,
                        'Accepted (' . $promise . ') but the "' . $promiseCfg['template'] . '" confirmation FAILED to send.'
                    );
                }
            }

            // Mark original order as converted
            $originalOrder->update(['converted' => 1]);

            // ⭐ If this was a khaas_storage order, create the storage tracking record.
            // The BU ID was stored on the approval queue record at placement time —
            // no config lookups or note parsing needed.
            $khaasTrackingWarning = null;
            if ($originalOrder->external_source === 'khaas_storage') {
                $businessUnitId = $originalOrder->khaas_business_unit_id ?? 2;

                try {
                    DB::table('t_crm_khaas_storage_order')->insert([
                        'order_id' => $convertedOrder->id,
                        'khaas_business_unit_id' => (int) $businessUnitId,
                        'status' => 'pending',
                        'notes' => $originalOrder->note,
                        'created_by' => $originalOrder->created_by ?? auth()->id(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    Log::info('Khaas storage tracking record created', [
                        'order_id' => $convertedOrder->id,
                        'business_unit_id' => $businessUnitId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to create khaas storage tracking record', [
                        'converted_order_id' => $convertedOrder->id,
                        'business_unit_id' => $businessUnitId,
                        'error' => $e->getMessage(),
                    ]);
                    $khaasTrackingWarning = 'Order converted but frozen meat tracking record failed — please check logs.';
                }
            }
            
            // ⭐ AUTO-GEOCODE: If customer has no coordinates, try to geocode their address
            // This ensures converted Shopify orders get geocoded addresses just like new orders.
            // Same rule as the new-order path: refresh whenever there is no VERIFIED
            // pin, which also replaces stale old-engine pins. (SH-21417 came through
            // here on Jul-25, failed to geocode, and was silently dropped from the
            // dispatch four hours later.)
            if ($convertedOrder->customer_id) {
                $customer = \App\Models\CRM\CustomerModel::find($convertedOrder->customer_id);
                if ($customer && \App\Services\GeocodingService::needsGeocodeRefresh($customer)) {
                    // Geocode in background (don't block order conversion)
                    try {
                        \App\Services\GeocodingService::geocodeCustomer(
                            $convertedOrder->customer_id,
                            (bool) ($customer->geocoded_latitude && $customer->geocoded_longitude),
                            ['trigger' => 'shopify_convert', 'order_id' => $convertedOrder->id, 'user_id' => auth()->id()]
                        );
                        \Log::info('Auto-geocoding triggered for Shopify converted order', [
                            'order_id' => $convertedOrder->id,
                            'customer_id' => $convertedOrder->customer_id
                        ]);
                    } catch (\Exception $e) {
                        \Log::warning('Auto-geocoding failed for converted Shopify order customer', [
                            'order_id' => $convertedOrder->id,
                            'customer_id' => $convertedOrder->customer_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            
            // Auto-detect delivery region for converted order
            if ($convertedOrder->customer_id || $convertedOrder->id) {
                try {
                    \App\Services\RegionDetectionService::detectAndSaveForOrder($convertedOrder->id, $convertedOrder->customer_id);
                } catch (\Exception $e) {
                    \Log::warning('Auto region detection failed for Shopify converted order', [
                        'order_id' => $convertedOrder->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // ⭐ Proof-before-order (Sep-2026): the customer often pays at Shopify
            // checkout and sends the screenshot BEFORE this approval click, so her
            // proof was matched while this order did not exist and got parked on
            // an old invoice. Now that the order is real, re-run HER unresolved
            // screenshots only (see PaymentSignalMatcher::rematchCustomerProofs).
            // Signal tables only; never blocks the conversion.
            if ($convertedOrder->customer_id) {
                try {
                    $proofRematch = app(\App\Services\Payments\Signals\PaymentSignalMatcher::class)
                        ->rematchCustomerProofs((int) $convertedOrder->customer_id, 'shopify_convert');
                    if (!empty($proofRematch['candidates'])) {
                        \Log::info('Proof re-match after Shopify conversion', [
                            'order_id' => $convertedOrder->id,
                            'customer_id' => $convertedOrder->customer_id,
                        ] + $proofRematch);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Proof re-match after Shopify conversion failed', [
                        'order_id' => $convertedOrder->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Auto location request: queue this customer if the automation is ON and
            // they qualify (no-op when the switch is off). Never blocks conversion.
            // Skipped for orders parked as 'pending' for a future day — those request
            // the location on activation instead (Option B, in OrderModel::changeStatus).
            if ($convertedOrder->customer_id && !$parkedPending) {
                try {
                    $locSvc = app(\App\Services\Location\OpenOrderLocationService::class);
                    $locSvc->enqueue((int) $convertedOrder->customer_id, (int) $convertedOrder->id, 'shopify_convert');
                    // Inside the window this sends instantly (drain fires off-request,
                    // never blocks); outside it no-ops and the row waits for the morning.
                    $locSvc->fireFromRequest();
                } catch (\Throwable $e) {
                    \Log::warning('Auto location-request enqueue failed for converted order', [
                        'order_id' => $convertedOrder->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Prepare response message with any warnings
            $message = 'Order converted successfully with product names and prices from your system';
            $allWarnings = $validationResult['warnings'] ?? [];
            if ($khaasTrackingWarning) {
                $allWarnings[] = $khaasTrackingWarning;
            }
            if (!empty($allWarnings)) {
                $message .= '. Warnings: ' . implode(', ', $allWarnings);
            }
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'original_order_id' => $originalOrder->id,
                'converted_order_id' => $convertedOrder->id,
                'converted_order' => $convertedOrder->load(['customer', 'lineItems']),
                'price_changes' => $validationResult['price_changes'],
                'warnings' => $allWarnings,
                // Delivery-promise outcome (for the UI toast): what was sent + where
                // the order landed. null promise = plain accept, no message.
                'delivery_promise' => $promiseApplies ? $promise : null,
                'order_status' => $convertedOrder->order_status,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a delivery-confirmation WhatsApp template to a converted order's customer
     * (the accept-button "delivery promise"). Template vars: {{1}} = customer name,
     * {{2}} = order number. Fully NON-FATAL — a send failure never affects the
     * conversion. Mirrors the direct-send pattern in OpenOrderLocationService::sendBulk.
     *
     * @return bool true only when WhatsApp accepted the template. The caller writes
     *              this into the order's Activity Log, so "sent" must mean sent.
     */
    private function sendDeliveryPromiseMessage($order, string $templateName): bool
    {
        try {
            $customerId = (int) ($order->customer_id ?? 0);
            if ($customerId <= 0 || $templateName === '') {
                return false;
            }

            $cust = \DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->first(['first_name', 'last_name', 'phone', 'phone_normalized']);
            if (!$cust) {
                return false;
            }

            $rawPhone = $cust->phone ?: $cust->phone_normalized;
            if (empty($rawPhone)) {
                \Log::info('Delivery-promise message skipped — customer has no phone', [
                    'order_id' => $order->id ?? null, 'template' => $templateName,
                ]);
                return false;
            }

            $wa = app(\App\Services\WhatsAppService::class);
            // Dial-resolve (known-number override; no-op for PK numbers).
            $phone = $wa->resolveDialPhone((string) $rawPhone);
            $name = trim(((string) $cust->first_name) . ' ' . ((string) $cust->last_name));
            $name = $name !== '' ? $name : 'Customer';
            $orderNumber = (string) ($order->order_number ?? '');

            // Templates carry 2 body vars: {{1}} name, {{2}} order number.
            $resp = $wa->sendTemplateMessage($phone, $templateName, 'en', [$name, $orderNumber]);

            if (!empty($resp['success'])) {
                // Persist the outbound to the conversation timeline (messages inbox).
                try {
                    $conv = $wa->findOrCreateConversation($phone);
                    if ($conv) {
                        $wa->saveOutboundMessage(
                            $conv->id,
                            $resp,
                            'template',
                            'Order accepted: ' . $templateName,
                            auth()->check() ? auth()->id() : null,
                            $templateName,
                            ['1' => $name, '2' => $orderNumber]
                        );
                    }
                } catch (\Throwable $e) {
                    \Log::debug('Delivery-promise saveOutboundMessage failed (non-fatal)', [
                        'order_id' => $order->id ?? null, 'error' => $e->getMessage(),
                    ]);
                }

                // The template reached WhatsApp. Persisting it to the conversation
                // timeline is best-effort and deliberately does NOT change this.
                return true;
            }

            \Log::warning('Delivery-promise template send failed', [
                'order_id' => $order->id ?? null,
                'template' => $templateName,
                'error' => $resp['error'] ?? 'unknown',
            ]);
            return false;
        } catch (\Throwable $e) {
            \Log::warning('sendDeliveryPromiseMessage failed (non-fatal)', [
                'order_id' => $order->id ?? null, 'template' => $templateName, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validate SKUs and recalculate order totals based on local product prices
     */
    private function validateAndRecalculateOrder($shopifyOrder)
    {
        // Khaas storage orders already have local product IDs and correct prices —
        // skip the Shopify SKU lookup and just pass line items through
        if ($shopifyOrder->external_source === 'khaas_storage') {
            $recalculatedLineItems = [];
            $newSubtotal = 0;
            foreach ($shopifyOrder->lineItems as $lineItem) {
                $lineItemData = $lineItem->toArray();
                unset($lineItemData['id'], $lineItemData['order_id'], $lineItemData['created_at'], $lineItemData['updated_at']);
                $recalculatedLineItems[] = $lineItemData;
                $newSubtotal += (float) $lineItem->line_total;
            }
            return [
                'success' => true,
                'recalculated_line_items' => $recalculatedLineItems,
                'new_subtotal' => $newSubtotal,
                'new_total' => $newSubtotal + (float) $shopifyOrder->shipping_total - (float) $shopifyOrder->discount_total,
                'price_changes' => [],
                'warnings' => [],
            ];
        }

        $validationErrors = [];
        $warnings = [];
        $priceChanges = [];
        $recalculatedLineItems = [];
        $newSubtotal = 0;
        
        // Validate coupon if present
        if ($shopifyOrder->coupon_code) {
            $coupon = \App\Models\CRM\CouponModel::where('code', $shopifyOrder->coupon_code)
                ->where('is_active', true)
                ->first();
            
            if (!$coupon) {
                $warnings[] = "Coupon '{$shopifyOrder->coupon_code}' not found in your system - please add it manually";
            }
        }
        
        // Process each line item
        foreach ($shopifyOrder->lineItems as $lineItem) {
            if (!$lineItem->sku) {
                $validationErrors[] = "Line item '{$lineItem->name}' has no SKU";
                continue;
            }
            
            // Find product variant by SKU (with product relationship for name)
            $productVariants = \App\Models\CRM\ProductVariantModel::with('product')
                ->where('sku', $lineItem->sku)->get();
            
            if ($productVariants->isEmpty()) {
                $validationErrors[] = "SKU '{$lineItem->sku}' not found in your products";
                continue;
            }
            
            if ($productVariants->count() > 1) {
                $validationErrors[] = "SKU '{$lineItem->sku}' found in multiple products - please ensure unique SKUs";
                continue;
            }
            
            $productVariant = $productVariants->first();
            $originalPrice = (float) $lineItem->unit_price;
            $newPrice = (float) $productVariant->price;

            // Get the product name from our system
            $localProductName = $productVariant->product ? $productVariant->product->title : null;
            $quantity = (int) $lineItem->quantity;

            // 📦 Bundled product (Oct-2026): Shopify's "1 × EK200 @ 1,500" is really
            // packets of the products inside (2 × Samosa @ 500 + 1 × Roll @ 500). It
            // becomes ONE ORDER LINE PER PRODUCT INSIDE, each that product's own sku /
            // variant / product at the packet price entered on the bundle — see
            // App\Services\CRM\ProductBundle. resolve() never throws and never stops a
            // conversion: a broken bundle stays one line at the bundle price (its variant
            // price is kept = the bundle total) with a warning for staff.
            [$bundle, $bundleWarning] = \App\Services\CRM\ProductBundle::resolve($productVariant);
            if ($bundleWarning) {
                $warnings[] = $bundleWarning;
            }
            if ($bundle) {
                $bundleLine = $lineItem->toArray();
                unset($bundleLine['id'], $bundleLine['order_id'], $bundleLine['created_at'], $bundleLine['updated_at']);
                $expanded = \App\Services\CRM\ProductBundle::expandLine($bundleLine, $bundle, (float) $quantity);
                $bundleTotal = round(array_sum(array_column($expanded, 'line_total')), 2);
                $shopifyTotal = $quantity * $originalPrice;
                if (abs($bundleTotal - $shopifyTotal) >= 1) {
                    // Not blocking — our prices win, as for every line — but the packet
                    // prices on the bundle should add up to the Shopify price.
                    $warnings[] = "Bundle {$lineItem->sku}: Shopify charged Rs " . number_format($shopifyTotal)
                        . ', our order says Rs ' . number_format($bundleTotal)
                        . ' — check the packet prices on the bundle in Products';
                }
                $priceChanges[] = [
                    'sku' => $lineItem->sku,
                    'original_name' => $lineItem->name,
                    'new_name' => implode(' + ', array_column($expanded, 'name')),
                    'name_changed' => true,
                    'original_price' => $originalPrice,
                    'new_price' => null,
                    'price_changed' => abs($bundleTotal - $shopifyTotal) >= 1,
                    'quantity' => $quantity,
                    'original_total' => $shopifyTotal,
                    'new_total' => $bundleTotal,
                    'bundle_lines' => count($expanded),
                ];
                foreach ($expanded as $row) {
                    $recalculatedLineItems[] = $row;
                }
                $newSubtotal += $bundleTotal;
                continue;
            }

            // Calculate new line total
            $newLineTotal = $quantity * $newPrice;
            $originalLineTotal = $quantity * $originalPrice;

            // Track price and name changes
            $originalName = $lineItem->name;
            $nameChanged = $localProductName && $localProductName !== $originalName;
            
            if ($originalPrice != $newPrice || $nameChanged) {
                $priceChanges[] = [
                    'sku' => $lineItem->sku,
                    'original_name' => $originalName,
                    'new_name' => $nameChanged ? $localProductName : $originalName,
                    'name_changed' => $nameChanged,
                    'original_price' => $originalPrice,
                    'new_price' => $newPrice,
                    'price_changed' => $originalPrice != $newPrice,
                    'quantity' => $quantity,
                    'original_total' => $originalLineTotal,
                    'new_total' => $newLineTotal
                ];
            }
            
            // Prepare recalculated line item
            $lineItemData = $lineItem->toArray();
            unset($lineItemData['id']);
            unset($lineItemData['order_id']);
            unset($lineItemData['created_at']);
            unset($lineItemData['updated_at']);
            
            // ⭐ FIX: Map Shopify external IDs to LOCAL variant_id and product_id
            // Without this, inventory deduction in storeOrderFromApi fails because
            // it tries to find ProductVariantModel by Shopify's external ID
            $lineItemData['variant_id'] = $productVariant->id;
            $lineItemData['product_id'] = $productVariant->product_id;

            // Update with new prices
            $lineItemData['unit_price'] = $newPrice;
            $lineItemData['line_total'] = $newLineTotal;
            $lineItemData['line_subtotal'] = $newLineTotal; // Assuming no line-level discounts
            
            // Update with product name from our system (if available)
            if ($localProductName) {
                $lineItemData['name'] = $localProductName;
            }
            
            $recalculatedLineItems[] = $lineItemData;
            $newSubtotal += $newLineTotal;
        }
        
        // If there are validation errors, stop conversion
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'message' => 'Cannot convert order due to the following issues: ' . implode(', ', $validationErrors),
                'errors' => $validationErrors
            ];
        }
        
        // Calculate new total (preserve shipping, tax, tip, and discount structure)
        $shippingTotal = (float) $shopifyOrder->shipping_total;
        $taxTotal = (float) $shopifyOrder->total_tax;
        $tipAmount = (float) ($shopifyOrder->tip_amount ?? 0);
        $discountTotal = (float) $shopifyOrder->discount_total;
        
        $newTotal = $newSubtotal + $shippingTotal + $taxTotal + $tipAmount - $discountTotal;
        
        return [
            'success' => true,
            'recalculated_line_items' => $recalculatedLineItems,
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
            'price_changes' => $priceChanges,
            'warnings' => $warnings
        ];
    }

    /**
     * Ignore a Shopify approval — optionally telling the customer why.
     *
     * ⭐⭐ ONE DOOR, FOUR SURFACES (Sep-3 2026). The web approvals drawer, the web
     *    Shopify table and both mobile store screens already POST here, so the three
     *    choices (quietly / outside our delivery area / customer asked to cancel) are
     *    implemented once, right here, and every surface gets them.
     *
     * ⚠⚠ THE MESSAGE NEVER GATES THE IGNORE. The order is stamped `converted = 2`
     *    FIRST and the response is a success whatever WhatsApp does — a missing
     *    template or a dead number must not leave an order sitting in the approvals
     *    queue. The messaging outcome rides back in `messaging` so the caller can say
     *    "ignored, but not messaged" instead of pretending.
     *
     * @param  Request $request  optional `reason`: none | out_of_area | customer_request
     */
    public function ignoreOrder(Request $request, $id)
    {
        try {
            // Find the original Shopify order in the new Shopify table
            $originalOrder = \App\Models\CRM\ShopifyOrderModel::findOrFail($id);

            // Check if already converted or ignored.
            // ⚠ Read the RAW column, not the attribute: the model casts `converted` to
            //   boolean, so `converted == 1` is true for the ignored value 2 as well and
            //   an already-ignored order used to be reported as "already converted".
            if ($originalOrder->converted) {
                $raw = (int) $originalOrder->getRawOriginal('converted');
                $status = $raw === 2 ? 'ignored' : 'converted';
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }

            $reason = \App\Services\WhatsApp\OrderCancellationNotifier::normalize($request->input('reason'));

            // Mark order as ignored
            $originalOrder->update(['converted' => 2]);

            // …then the courtesy message, which is allowed to fail without taking the
            // ignore with it. `notify()` never throws and never sends for reason=none.
            $messaging = app(\App\Services\WhatsApp\OrderCancellationNotifier::class)
                ->notify($originalOrder, $reason, optional($request->user())->id ?? auth()->id());

            $message = 'Order marked as ignored - no invoice will be created';
            if ($reason !== \App\Services\WhatsApp\OrderCancellationNotifier::REASON_NONE) {
                $message .= '. ' . $messaging['detail'];
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'order_id' => $originalOrder->id,
                'reason' => $reason,
                'messaging' => $messaging,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to ignore order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Public accessor for findOrder (used by other controllers like WhatsApp invoice sending)
     */
    public function findOrderPublic($id)
    {
        return $this->findOrder($id);
    }

    /**
     * Helper method to resolve an order by id from the correct table.
     *
     * ⚠️ CRITICAL: the Shopify approval queue (t_crm_shopify_order) and the live
     * orders table (t_crm_prod_order) have INDEPENDENT, OVERLAPPING auto-increment
     * id sequences. The same numeric id routinely exists in BOTH tables as two
     * completely unrelated orders (e.g. staging id 6488 = a brand-new Shopify
     * order, while prod id 6488 = an old delivered order for a different
     * customer). We therefore must NOT "guess" by probing one table then the
     * other — doing so previously caused a live order detail page to render the
     * wrong Shopify order, and a status change to mutate the wrong live order.
     *
     * The caller's CONTEXT is authoritative and is passed via the `source`
     * request param (set by the front-end based on which screen you're on):
     *   - source=shopify → Shopify Approvals screen → staging table ONLY
     *   - anything else  → live production orders     → prod table ONLY
     *
     * The two screens never overlap, so there is intentionally no cross-table
     * fallback.
     */
    private function findOrder($id, $withRelations = ['customer', 'lineItems', 'assignedRider', 'discounts'], $source = null)
    {
        if (!in_array('lineItems', $withRelations, true)) {
            $withRelations[] = 'lineItems';
        }
        if (!in_array('lineItems.variant', $withRelations, true)) {
            $withRelations[] = 'lineItems.variant';
        }

        if ($source === null) {
            $source = request()->query('source');
        }

        if ($source === 'shopify') {
            // Shopify staging orders are temporary (before conversion) and have no rider.
            $shopifyRelations = array_diff($withRelations, ['assignedRider']);
            return \App\Models\CRM\ShopifyOrderModel::with($shopifyRelations)->findOrFail($id);
        }

        return \App\Models\CRM\OrderModel::with($withRelations)->findOrFail($id);
    }

    private function getQurbaniInvoiceFields(): array
    {
        $fieldNames = DB::table('t_crm_qurbani_field_options')
            ->where('show_in_invoice', 1)
            ->groupBy('field_name')
            ->pluck('field_name')
            ->toArray();

        $desiredOrder = ['qurbani_day', 'qurbani_delivery_type', 'qurbani_slot', 'qurbani_region', 'qurbani_sub_region'];
        $ordered = [];
        foreach ($desiredOrder as $f) {
            if (in_array($f, $fieldNames)) $ordered[] = $f;
        }
        foreach ($fieldNames as $f) {
            if (!in_array($f, $ordered)) $ordered[] = $f;
        }
        return $ordered;
    }

    private function isQurbaniOrder($order): bool
    {
        if (method_exists($order, 'hasQurbaniItems') && $order->hasQurbaniItems()) return true;
        if (!empty($order->qurbani_day) || !empty($order->qurbani_region)) return true;
        $orderNum = strtoupper($order->order_number ?? '');
        return str_starts_with($orderNum, 'QUR');
    }

    public function filter(Request $request)
    {
        try {
            $user = auth()->user();
            $source = $request->get('source', 'other');
            $tab = $request->get('tab', 'all');
            $search = $request->get('search', '');
            $status = $request->get('status', '');
            $date = $request->get('date', '');
            $deliveryDate = $request->get('delivery_date', '');
            $orderMonth = $request->get('order_month', '');
            $deliveryMonth = $request->get('delivery_month', '');

            // Check permissions. Mobile (rider-mode) requests may view the Shopify queue
            // via the MOBILE 'view_shopify_orders' permission; web keeps the web permission.
            // Transitional OR keeps web-granted roles working before the mobile grant is
            // seeded (Phase 0.7). Non-mobile (web) behaviour is unchanged.
            $canViewShopify = $user->hasPermission('view_shopify_orders')
                || ($request->is('api/rider/*') && $user->hasMobilePermission('view_shopify_orders'));
            $canViewAllOrders = $user->hasPermission('view_all_orders');
            
            // Start with base query based on source
            if ($source === 'shopify') {
                // Return empty if user can't view Shopify
                if (!$canViewShopify) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to view Shopify orders.',
                        'data' => []
                    ], 403);
                }
                
                // Use Shopify orders table
                $query = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems']);
                
                // Apply tab filter for Shopify orders
                if ($tab === 'approvals') {
                    $query->where(function($q){
                        $q->whereNull('converted')->orWhere('converted', 0);
                    });
                }
            } else {
                // Use main orders table (non-Shopify)
                $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems', 'assignedRider'])
                    ->where(function($q) {
                        $q->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                    });
                
                // Detect if request is from mobile API (rider mode)
                $isMobileRequest = $request->is('api/rider/*');
                
                // Permission-based filtering:
                // - Mobile requests (rider mode): ALWAYS filter to assigned orders only, even for admins
                // - Web requests: users without view_all_orders see only their assigned orders
                if ($isMobileRequest || !$canViewAllOrders) {
                    $query->where('assigned_rider_user_id', auth()->id());
                }
                
                // Apply tab filter for open orders and riders
                if ($tab === 'open' || $tab === 'riders') {
                    $query->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
                    // Exclude qurbani orders from store mode / web open orders,
                    // but NOT from rider mode (riders need to see qurbani orders assigned to them)
                    if (!$isMobileRequest) {
                        $query->where(function ($q) {
                            $q->whereNull('qurbani_day')
                              ->whereDoesntHave('lineItems', function ($li) {
                                  $li->whereHas('product', function ($p) {
                                      $p->whereRaw("LOWER(attribute_1) = 'qurbani'");
                                  });
                              });
                        });
                    }
                }
            }
            
            // Apply search filter
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('order_number', 'like', '%' . $search . '%')
                      ->orWhere('name', 'like', '%' . $search . '%')
                      ->orWhereHas('customer', function($customerQuery) use ($search) {
                          $customerQuery->where('name', 'like', '%' . $search . '%')
                                       ->orWhere('phone', 'like', '%' . $search . '%')
                                       ->orWhere('email', 'like', '%' . $search . '%');
                      });
                });
            }
            
            // Apply status filter
            if (!empty($status)) {
                $query->where('order_status', $status);
            }
            
            // Apply order date filter
            if (!empty($date)) {
                $query->whereDate('order_date', $date);
            }

            // Apply delivery date filter on non-Shopify orders through status history
            if ($source !== 'shopify' && !empty($deliveryDate)) {
                $query->whereExists(function($q) use ($deliveryDate) {
                    $q->select(\DB::raw(1))
                      ->from('t_crm_order_status_history as h')
                      ->whereColumn('h.order_id', 't_crm_prod_order.id')
                      ->where('h.status_code', 'delivered')
                      ->whereDate('h.changed_at', $deliveryDate);
                });
            }
            
            // Apply order month filter if provided (YYYY-MM format)
            if (!empty($orderMonth)) {
                $query->whereRaw("DATE_FORMAT(order_date, '%Y-%m') = ?", [$orderMonth]);
            }
            
            // Apply delivery month filter (non-Shopify orders only) using status history
            if ($source !== 'shopify' && !empty($deliveryMonth)) {
                $query->whereExists(function($q) use ($deliveryMonth) {
                    $q->select(\DB::raw(1))
                      ->from('t_crm_order_status_history as h')
                      ->whereColumn('h.order_id', 't_crm_prod_order.id')
                      ->where('h.status_code', 'delivered')
                      ->whereRaw("DATE_FORMAT(h.changed_at, '%Y-%m') = ?", [$deliveryMonth]);
                });
            }
            
            // Get results (limit to 100 for performance)
            $orders = $query->orderBy('order_date', 'desc')->limit(100)->get();

            // ⭐ ENHANCEMENT: Add customer order counts for mobile app
            // Uses combined production + history counts (same logic as CustomerController)
            $customerIds = $orders->pluck('customer_id')->unique()->filter()->values()->all();
            $customerOrderCounts = [];
            if (!empty($customerIds)) {
                $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
                $hasHistoryTable = \DB::getSchemaBuilder()->hasTable('t_crm_history_order');

                if ($hasHistoryTable) {
                    $counts = \DB::select("
                        SELECT customer_id, SUM(order_count) as order_count
                        FROM (
                            SELECT customer_id, COUNT(*) as order_count
                            FROM t_crm_prod_order
                            WHERE customer_id IN ({$placeholders})
                              AND (external_source != 'shopify' OR external_source IS NULL)
                              AND order_status IN ('delivered', 'completed')
                            GROUP BY customer_id
                            UNION ALL
                            SELECT customer_id, COUNT(*) as order_count
                            FROM t_crm_history_order
                            WHERE customer_id IN ({$placeholders})
                              AND order_status = 'delivered'
                            GROUP BY customer_id
                        ) combined
                        GROUP BY customer_id
                    ", array_merge($customerIds, $customerIds));
                } else {
                    $counts = \DB::select("
                        SELECT customer_id, COUNT(*) as order_count
                        FROM t_crm_prod_order
                        WHERE customer_id IN ({$placeholders})
                          AND (external_source != 'shopify' OR external_source IS NULL)
                          AND order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ", $customerIds);
                }

                foreach ($counts as $row) {
                    $customerOrderCounts[$row->customer_id] = $row->order_count;
                }
            }

            $regionMap = [];
            try {
                $regionMap = \DB::table('t_ops_delivery_region')
                    ->where('is_active', 1)
                    ->pluck('name', 'id')
                    ->toArray();
            } catch (\Exception $e) {}

            // ⭐ Resolve who dispatched each order (bulk, no N+1) so the rider can
            //    see "Dispatched by <name>" when a store manager dispatched it.
            $dispatcherNames = [];
            try {
                $dispatcherIds = $orders->pluck('eta_calculated_by')->filter()->unique()->values()->all();
                if (!empty($dispatcherIds)) {
                    $dispatcherNames = \DB::table('t_sys_user')
                        ->whereIn('id', $dispatcherIds)
                        ->pluck('fullname', 'id')
                        ->toArray();
                }
            } catch (\Exception $e) {}

            // Jun-2026: batch-load each customer's latest WhatsApp button reply
            // ("Confirm Wednesday" / "Split delivery" / "Cancel order" etc.) so the
            // Shopify approval queue can show an "options received" marker before
            // converting. Shopify-source only (that's where the button templates
            // go out); one service call for the whole page; never throws.
            $customerReplyMap = [];
            $orderReplyMap = [];
            if ($source === 'shopify') {
                $replyCustomerIds = $orders->pluck('customer_id')->filter()->unique()->values()->all();
                if (!empty($replyCustomerIds)) {
                    $customerReplyMap = \App\Services\WhatsApp\OrderReplyService::latestReplyForCustomers($replyCustomerIds);
                }
                // Per-ORDER replies (button tap linked to the exact order via the
                // template's context.id). Preferred over the per-customer map; the
                // map is empty (→ silent fallback) until the linkage column exists.
                $replyOrderNumbers = $orders->pluck('order_number')->filter()->unique()->values()->all();
                if (!empty($replyOrderNumbers)) {
                    $orderReplyMap = \App\Services\WhatsApp\OrderReplyService::latestReplyForOrders($replyOrderNumbers);
                }
            }

            // Invoice-send status map: order_number → sent_at / failed / error,
            // so the orders page can show a truthful tick (green = a successful
            // send exists, red = the LATEST attempt failed per the delivery
            // webhook). Bulk + indexed; non-fatal.
            $invoiceSentMap = [];
            try {
                $invOrderNumbers = $orders->pluck('order_number')->filter()->unique()->values()->all();
                if (!empty($invOrderNumbers)) {
                    $invoiceSentMap = self::invoiceSendStatusMap($invOrderNumbers);
                }
            } catch (\Throwable $e) {
                $invoiceSentMap = [];
            }

            // Aug-2026 — payment-proof status for the RIDER's order list, so a
            // customer who paid by WhatsApp transfer is visible on the card
            // before he knocks (and, on a CASH order, before he asks for money
            // that has already been sent).
            //
            // Mobile only, deliberately: the web orders page builds its own map
            // in index() with the default suppressSettled, and quietly changing
            // the shape of its refetch is not this feature's business. The rider
            // is a RECORD surface — an approved payment must still read as
            // received — hence suppressSettled:false, matching getOrderDetails.
            // Bulk (the query is capped at 100 rows above); non-fatal.
            // NEVER for source=shopify: those rows come from t_crm_shopify_order,
            // whose auto-increment ids overlap t_crm_prod_order's, and payment
            // signals key on PRODUCTION order ids. Looking one up by a staging id
            // would decorate a Shopify approval with an unrelated live order's
            // payment — the id-collision trap this codebase has been bitten by
            // before. Staging orders have no payments yet anyway.
            $riderProofMap = [];
            try {
                if ($source !== 'shopify' && $request->is('api/rider/*') && config('payment_signals.enabled')) {
                    $proofOrderIds = $orders->pluck('id')->filter()->values()->all();
                    if (!empty($proofOrderIds)) {
                        $riderProofMap = app(\App\Services\Payments\Signals\PaymentProofStatusService::class)
                            ->forOrders($proofOrderIds, suppressSettled: false);
                    }
                }
            } catch (\Throwable $e) {
                $riderProofMap = [];
            }

            // Add customer order count and region info to each order
            $orders->transform(function($order) use ($customerOrderCounts, $regionMap, $dispatcherNames, $customerReplyMap, $orderReplyMap, $invoiceSentMap, $riderProofMap) {
                $orderCount = $customerOrderCounts[$order->customer_id] ?? 0;
                $isNewCustomer = $orderCount <= 1;

                $order->customer_order_count = $orderCount;
                $order->customer_is_new = $isNewCustomer;
                $order->customer_badge = $isNewCustomer ? 'NEW' : "{$orderCount} orders";
                $custRegionId = $order->customer->delivery_region_id ?? null;
                $order->delivery_region_id = $custRegionId;
                $order->delivery_region_name = $custRegionId ? ($regionMap[$custRegionId] ?? null) : null;
                $order->eta_calculated_by_name = $order->eta_calculated_by
                    ? ($dispatcherNames[$order->eta_calculated_by] ?? null)
                    : null;
                // Prefer the exact per-order reply; fall back to the customer's latest.
                $perOrderReply = (!empty($order->order_number) && isset($orderReplyMap[$order->order_number]))
                    ? $orderReplyMap[$order->order_number] : null;
                $order->customer_reply = $perOrderReply ?? ($customerReplyMap[$order->customer_id] ?? null);

                // Invoice-send tick (orders page): green when a successful invoice
                // template went out via the API; red when the latest attempt failed.
                $invNum = $order->order_number ?? '';
                $invSt = ($invNum !== '') ? ($invoiceSentMap[$invNum] ?? null) : null;
                $order->invoice_sent = (bool) ($invSt['sent_at'] ?? null);
                $order->invoice_sent_at = $invSt['sent_at'] ?? null;
                $order->invoice_send_failed = (bool) ($invSt['failed'] ?? false);
                $order->invoice_send_error = $invSt['error'] ?? null;

                // Payment-proof badge for the rider's card (empty map on web).
                $order->payment_proof = $riderProofMap[$order->id] ?? null;

                return $order;
            });

            // ⭐ SMART SYNC: Update sync timestamp when rider fetches orders
            // This tracks when the mobile app last communicated with the server
            if ($source === 'other' && auth()->check()) {
                $riderId = auth()->id();
                
                // Clear any pending sync flags
                \DB::table('t_crm_prod_order')
                    ->where('assigned_rider_user_id', $riderId)
                    ->where('rider_sync_required', true)
                    ->update([
                        'rider_sync_required' => false,
                        'rider_last_sync_at' => now()
                    ]);
                
                // ⭐ Also update at least one order's sync time to track "last seen"
                // This ensures we always have a recent timestamp for the rider
                \DB::table('t_crm_prod_order')
                    ->where('assigned_rider_user_id', $riderId)
                    ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                    ->orderBy('id', 'desc')
                    ->limit(1)
                    ->update([
                        'rider_last_sync_at' => now()
                    ]);
            }

            // Provide counts for Shopify tabs so the frontend can render badges correctly
            $shopifyAllCount = null;
            $shopifyApprovalsCount = null;
            $otherCountAll = null;
            // Always provide counts for badges across tabs
            $shopifyAllCount = \App\Models\CRM\ShopifyOrderModel::count();
            $shopifyApprovalsCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count();
            $otherCountAll = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->count();
            // NF: same non-qurbani exclusion as the tab badge, so live filter responses don't re-inflate the count.
            $openCountAllQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
            $this->applyNonQurbaniFilter($openCountAllQuery);
            $openCountAll = $openCountAllQuery->count();
            
            return response()->json([
                'success' => true,
                'orders' => $orders->toArray(),
                'total' => $orders->count(),
                'shopify_all_count' => $shopifyAllCount,
                'shopify_approvals_count' => $shopifyApprovalsCount,
                'other_count' => $otherCountAll,
                'open_count' => $openCountAll,
                'tab' => $tab,
                'source' => $source,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Order filter error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Filter failed: ' . $e->getMessage(),
                'orders' => []
            ], 500);
        }
    }

    /**
     * Lightweight "are there new orders?" probe for the open-orders table's
     * "N new orders — click to load" pill (30s poll, only while the tab is
     * visible). Detects NEW orders by highest order id — which only ever
     * increases — instead of by a churny open-order COUNT. That matters under
     * heavy delivery volume: with a count, deliveries leaving the open set can
     * offset new arrivals so the total never rises and new orders go unnoticed;
     * an id high-water mark is immune to that (a delivery never mints a new id).
     *
     * Watches the LIVE orders table only (NF-created + accepted-Shopify orders
     * land here). Pending Shopify STAGING orders are covered by the separate
     * approvals-count badge, so source=shopify returns nothing here.
     *
     * Query params: after_id (highest id the client has seen), source, tab.
     * Returns: { success, count (# orders with id > after_id in scope),
     *            max_id (current highest id in scope = the client's next baseline) }.
     */
    public function newOrdersSince(Request $request)
    {
        try {
            $afterId = (int) $request->get('after_id', 0);
            $source  = $request->get('source', 'other');
            $tab     = $request->get('tab', 'open');

            // Shopify staging is the approvals badge's job — nothing to add here.
            if ($source === 'shopify') {
                return response()->json(['success' => true, 'count' => 0, 'max_id' => $afterId]);
            }

            $query = \DB::table('t_crm_prod_order as o')
                ->where(function ($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                });

            // Mirror filter()'s open/riders scoping so the count matches the table
            // the user is looking at (exclude finished statuses + qurbani orders).
            if ($tab === 'open' || $tab === 'riders') {
                $query->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                      ->whereNull('o.qurbani_day')
                      ->whereNotExists(function ($sub) {
                          $sub->select(\DB::raw(1))
                              ->from('t_crm_prod_order_line_item as li')
                              ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                              ->whereColumn('li.order_id', 'o.id')
                              ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                      });
            }

            $maxId = (int) ((clone $query)->max('o.id') ?? $afterId);
            $count = $afterId > 0 ? (int) (clone $query)->where('o.id', '>', $afterId)->count() : 0;

            return response()->json([
                'success' => true,
                'count'   => $count,
                'max_id'  => $maxId,
            ]);
        } catch (\Exception $e) {
            \Log::warning('newOrdersSince failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'count' => 0, 'max_id' => (int) $request->get('after_id', 0)]);
        }
    }

    /**
     * Get open orders status counts for status cards
     */
    public function getOpenOrdersStatusCounts(Request $request)
    {
        try {
            // Get all active statuses excluding completed ones
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // Build counts by normalizing order_status to canonical codes first
            $statusCounts = \DB::table('t_crm_prod_order as o')
                ->select([
                    \DB::raw("CASE 
                        WHEN o.order_status IN ('on-hold','on hold') THEN 'on_hold'
                        WHEN o.order_status = 'completed' THEN 'delivered'
                        WHEN o.order_status IN ('out-for-delivery','out for delivery') THEN 'out_for_delivery'
                        WHEN o.order_status IN ('pending','pending payment','pending-payment') THEN 'pending'
                        ELSE o.order_status END AS normalized_code"),
                    \DB::raw('COUNT(o.id) as count')
                ])
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNull('o.qurbani_day')
                ->whereNotExists(function($sub) {
                    $sub->select(\DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                        ->whereColumn('li.order_id', 'o.id')
                        ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                })
                ->groupBy('normalized_code');

            // Join to master to fetch display data and filter out excluded statuses via canonical codes
            $statusCounts = \DB::query()
                ->fromSub($statusCounts, 'c')
                ->join('t_crm_order_status_master as sm', 'sm.status_code', '=', 'c.normalized_code')
                ->where('sm.is_active', 1)
                ->whereNotIn('sm.status_code', $excludedStatuses)
                ->orderBy('sm.sequence_order')
                ->get([
                    'sm.status_code',
                    'sm.status_name',
                    'sm.icon',
                    'sm.color_class',
                    'c.count'
                ]);

            // Calculate total open orders count
            // NF: exclude qurbani so this matches the per-status counts above and the list view.
            $totalOpenCountQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', $excludedStatuses);
            $this->applyNonQurbaniFilter($totalOpenCountQuery);
            $totalOpenCount = $totalOpenCountQuery->count();

            // Delivered today (from history), non-shopify orders only
            $deliveredTodayCount = \DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->whereDate('h.changed_at', now()->toDateString())
                ->where('h.status_code', 'delivered')
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->count();

            // Calculate verified/unverified address counts for all open orders
            // NF: exclude qurbani to stay consistent with $totalOpenCount / the list view.
            $allOpenVerifiedQuery = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where(function($q) {
                    $q->whereNotNull('c.verified_location_url')
                      ->orWhere(function($q2) {
                          $q2->whereNotNull('c.latitude')
                             ->whereNotNull('c.longitude');
                      });
                });
            $this->applyNonQurbaniFilterDb($allOpenVerifiedQuery, 'o');
            $allOpenVerifiedCount = $allOpenVerifiedQuery->count();

            $allOpenUnverifiedCount = $totalOpenCount - $allOpenVerifiedCount;

            // Calculate verified/unverified address counts for "out_for_delivery" status
            // NF: exclude qurbani to stay consistent with the Out For Delivery status card count.
            $outForDeliveryTotalQuery = \DB::table('t_crm_prod_order as o')
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->where(function($q) {
                    $q->where('o.order_status', 'out_for_delivery')
                      ->orWhere('o.order_status', 'out-for-delivery')
                      ->orWhere('o.order_status', 'out for delivery');
                });
            $this->applyNonQurbaniFilterDb($outForDeliveryTotalQuery, 'o');
            $outForDeliveryTotal = $outForDeliveryTotalQuery->count();

            $outForDeliveryVerifiedQuery = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->where(function($q) {
                    $q->where('o.order_status', 'out_for_delivery')
                      ->orWhere('o.order_status', 'out-for-delivery')
                      ->orWhere('o.order_status', 'out for delivery');
                })
                ->where(function($q) {
                    $q->whereNotNull('c.verified_location_url')
                      ->orWhere(function($q2) {
                          $q2->whereNotNull('c.latitude')
                             ->whereNotNull('c.longitude');
                      });
                });
            $this->applyNonQurbaniFilterDb($outForDeliveryVerifiedQuery, 'o');
            $outForDeliveryVerifiedCount = $outForDeliveryVerifiedQuery->count();

            $outForDeliveryUnverifiedCount = $outForDeliveryTotal - $outForDeliveryVerifiedCount;

            return response()->json([
                'success' => true,
                'status_counts' => $statusCounts,
                'total_open_count' => $totalOpenCount,
                'delivered_today' => $deliveredTodayCount,
                'all_open_verified' => $allOpenVerifiedCount,
                'all_open_unverified' => $allOpenUnverifiedCount,
                'out_for_delivery_total' => $outForDeliveryTotal,
                'out_for_delivery_verified' => $outForDeliveryVerifiedCount,
                'out_for_delivery_unverified' => $outForDeliveryUnverifiedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Open orders status counts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch status counts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rider-wise breakdown of open orders with status counts
     */
    public function getRiderOrdersCounts(Request $request)
    {
        try {
            // Excluded statuses (completed orders)
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // Get open orders grouped by rider with status breakdown
            $riderCountsQuery = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->select([
                    'o.assigned_rider_user_id as rider_id',
                    'u.fullname as rider_name',
                    \DB::raw("CASE
                        WHEN o.order_status IN ('on-hold','on hold') THEN 'on_hold'
                        WHEN o.order_status = 'completed' THEN 'delivered'
                        WHEN o.order_status IN ('out-for-delivery','out for delivery') THEN 'out_for_delivery'
                        WHEN o.order_status IN ('pending','pending payment','pending-payment') THEN 'pending'
                        ELSE o.order_status END AS normalized_status"),
                    \DB::raw('COUNT(o.id) as count')
                ])
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->groupBy('o.assigned_rider_user_id', 'u.fullname', 'normalized_status')
                ->orderBy('u.fullname');

            // NF (Aug-2026): hide qurbani here TOO — the same exclusion total_open_count
            // and assigned_count below already apply, and the same one the Open Orders
            // list applies to the rows themselves. Qurbani completion is tracked on the
            // line items and never rolls up to order_status, so those orders sit "open"
            // for months; counted here they made the per-rider numbers disagree with the
            // very table they are used to filter (a rider showing "14 orders" whose
            // filter selects nothing). Totals and rows now come from one definition.
            $this->applyNonQurbaniFilterDb($riderCountsQuery, 'o');
            $riderCounts = $riderCountsQuery->get();

            // Organize data by rider
            $ridersData = [];
            $unassignedCount = 0;
            $unassignedBreakdown = [];

            foreach ($riderCounts as $record) {
                if ($record->rider_id) {
                    // Assigned rider
                    if (!isset($ridersData[$record->rider_id])) {
                        $ridersData[$record->rider_id] = [
                            'rider_id' => $record->rider_id,
                            'rider_name' => $record->rider_name,
                            'total_count' => 0,
                            'status_breakdown' => []
                        ];
                    }
                    $ridersData[$record->rider_id]['total_count'] += $record->count;
                    $ridersData[$record->rider_id]['status_breakdown'][$record->normalized_status] = $record->count;
                } else {
                    // Unassigned orders
                    $unassignedCount += $record->count;
                    $unassignedBreakdown[$record->normalized_status] = $record->count;
                }
            }

            // Convert to array for JSON response
            $ridersArray = array_values($ridersData);

            // Total open orders count
            // NF: exclude qurbani so the Riders tab totals match the Open Orders list.
            $totalOpenCountQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', $excludedStatuses);
            $this->applyNonQurbaniFilter($totalOpenCountQuery);
            $totalOpenCount = $totalOpenCountQuery->count();

            // Assigned orders count
            $assignedCountQuery = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })
            ->whereNotNull('assigned_rider_user_id')
            ->whereNotIn('order_status', $excludedStatuses);
            $this->applyNonQurbaniFilter($assignedCountQuery);
            $assignedCount = $assignedCountQuery->count();

            return response()->json([
                'success' => true,
                'riders' => $ridersArray,
                'unassigned_count' => $unassignedCount,
                'unassigned_breakdown' => $unassignedBreakdown,
                'total_open_count' => $totalOpenCount,
                'assigned_count' => $assignedCount,
                'riders_count' => count($ridersArray)
            ]);
        } catch (\Exception $e) {
            Log::error('Rider orders counts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rider counts: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getUserRole(Request $request)
    {
        $user = $request->user();
        if (!$user) return null;

        return \DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->value('r.type');
    }

    /**
     * Riders Map - Standalone Page
     * Shows riders with their live locations and order assignments
     */
    public function ridersMap()
    {
        // Check permission - users with view_all_orders or admin/manager roles can access
        $user = auth()->user();
        $userRole = $user->roles->first()->type ?? 'user';
        
        // Block riders from accessing the riders map page
        if ($userRole === 'rider') {
            return redirect()->route('orders.index')
                ->with('error', 'You do not have permission to view Riders Map.');
        }

        // Rider Reports (Phase 2) — gates the forensic layer of Day Review
        // (route, stops, pin-crossing verdicts), exactly as it gated the old
        // ⚠ Issues tab that Day Review replaced.
        $canViewRiderReports = $user->hasPermission('view_rider_reports');

        // 🏍️ Bikes shows money, so it is gated. Same rule the FleetFuel controller
        // enforces server-side — keep the two in sync. `view_bike_costs` is the
        // standalone key: it lets someone (e.g. a Khaas-mode user) see running
        // costs without any rider-ops or finance access tagging along.
        $canViewBikes = $canViewRiderReports
            || $user->hasPermission('web_menu_finance_hub')
            || $user->hasPermission('view_bike_costs');

        // Someone whose ONLY reason to be here is Bikes should land on Bikes and
        // not be shown the live board or delivery reviews at all.
        $bikesOnly = $canViewBikes && !$canViewRiderReports
            && !$user->hasPermission('view_orders')
            && !$user->hasPermission('view_all_riders')
            && !$user->hasPermission('web_menu_finance_hub');

        $canViewFleet = $canViewBikes;   // name kept: the blade + JS use `fleet`

        // The Bikes tab can file a petrol/maintenance claim inline. It posts to the
        // ordinary request endpoint, which needs the "expense" category id — looked
        // up here rather than in the view, and null-safe so the button simply does
        // nothing if that category is ever missing.
        $expenseCategoryId = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')
            ->value('id');

        return view('pages.riders-map.index',
            compact('canViewRiderReports', 'canViewFleet', 'bikesOnly', 'expenseCategoryId'));
    }

    /**
     * Open Order Quantities - Main Page
     * Shows hierarchical breakdown of quantities in open orders
     */
    public function openQuantities(Request $request)
    {
        // Check permission
        $user = auth()->user();
        if (!$user->hasPermission('view_open_quantities')) {
            return redirect()->route('dashboard')
                ->with('error', 'You do not have permission to view Open Order Quantities.');
        }
        
        // Get attribute labels from JSON file
        $labels = $this->getAttributeLabels();
        
        // Get available categories for filters
        $categories = \DB::table('t_crm_prod_product')
            ->select('product_type')
            ->whereNotNull('product_type')
            ->where('product_type', '!=', '')
            ->distinct()
            ->orderBy('product_type')
            ->pluck('product_type');

        return view('pages.orders.open-quantities', compact('labels', 'categories'));
    }

    /**
     * Open Order Quantities - Data API
     * Returns hierarchical quantity data based on drill-down level
     */
    public function openQuantitiesData(Request $request)
    {
        try {
            // Check permission
            $user = auth()->user();
            if (!$user->hasPermission('view_open_quantities')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this data.'
                ], 403);
            }
            
            // Get hierarchy from global settings (not from request)
            $hierarchySetting = \DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'hierarchy_levels')
                ->first();
            $hierarchy = $hierarchySetting ? json_decode($hierarchySetting->setting_value, true) : ['product_type', 'product_name', 'orders'];
            if (!is_array($hierarchy)) {
                $hierarchy = ['product_type', 'product_name', 'orders'];
            }
            
            $level = (int) $request->get('level', 0); // Current drill-down level
            
            // Handle filters - can be JSON string or already an array (from mobile)
            $filtersInput = $request->get('filters', '{}');
            if (is_array($filtersInput)) {
                $filters = $filtersInput;
            } else {
                $filters = json_decode($filtersInput, true);
            if (!is_array($filters)) {
                $filters = [];
                }
            }
            
            $dateRange = $request->get('date_range', 0); // Days to look back (0 = all time)

            // Excluded statuses now come from the central Order-Status rule service.
            // Phase 1: this returns the exact same value as the legacy read (this
            // environment's excluded_statuses setting → literal fallback), just centralised
            // so the Status Hub can own it later. No behaviour change.
            $excludedStatuses = app(\App\Services\CRM\OrderStatusRuleService::class)->quantitiesExcluded();

            Log::debug('Open Quantities Excluded Statuses:', ['excluded' => $excludedStatuses]);
            
            // DEBUG: Show orders that SHOULD be included
            $includedOrdersCount = \DB::table('t_crm_prod_order as o')
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where('o.order_date', '>=', Carbon::now()->subDays(20))
                ->count();
            
            $statusBreakdown = \DB::table('t_crm_prod_order as o')
                ->select('o.order_status', \DB::raw('COUNT(*) as count'))
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where('o.order_date', '>=', Carbon::now()->subDays(20))
                ->groupBy('o.order_status')
                ->get();
                
            Log::debug('Open Quantities Orders Analysis:', [
                'total_included_orders' => $includedOrdersCount,
                'status_breakdown' => $statusBreakdown->toArray(),
                'date_filter' => 'Last 20 days from ' . Carbon::now()->subDays(20)->toDateString()
            ]);
            
            Log::debug('Open Quantities Join Strategy:', [
                'note' => 'SKU-primary matching with fallbacks for manual orders',
                'paths' => [
                    '1' => 'li.sku -> pv.sku (PRIMARY - most reliable)',
                    '2' => 'li.variant_id -> pv.shopify_variant_id',
                    '3' => 'li.variant_id -> pv.id',
                    '4' => 'li.product_id -> pv.shopify_variant_id',
                    '5' => 'li.product_id -> pv.id',
                    '6' => 'li.product_id -> p.id (direct)'
                ]
            ]);

            // Build base query for open orders with line items
            // SKU-primary matching with fallbacks:
            // Priority 1: SKU match (most reliable for WooCommerce products)
            // Priority 2-5: Existing variant/product ID matches (for manual orders without SKU)
            // Priority 6: Direct product_id match
            // Priority 7: Name fallback (lowest priority, for legacy orders without any IDs)
            // FIX: Use exclusive/priority-based JOIN to prevent duplicate rows
            // When SKU exists and matches, don't also match via product_id/variant_id
            // This prevents cross-matches where li.product_id (WooCommerce ID) accidentally 
            // matches pv.shopify_variant_id of a different product
            $query = \DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function($join) {
                    // EXCLUSIVE matching: SKU match OR (fallbacks only when no SKU)
                    $join->where(function($q) {
                        // PRIORITY 1: SKU match (most reliable) - when SKU exists
                        $q->where(function($skuMatch) {
                            $skuMatch->whereNotNull('li.sku')
                                     ->where('li.sku', '!=', '')
                                     ->whereColumn('li.sku', 'pv.sku');
                        })
                        // PRIORITY 2-5: Fallbacks ONLY when no valid SKU exists
                        ->orWhere(function($fallback) {
                            $fallback->where(function($noSku) {
                                $noSku->whereNull('li.sku')
                                      ->orWhere('li.sku', '');
                            })
                            ->where(function($idMatch) {
                                $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.variant_id', 'pv.id')
                          ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.product_id', 'pv.id');
                            });
                        });
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function($join) {
                    // Product match: via variant (covers SKU match) or name fallback for legacy
                    $join->where(function($q) {
                        // Primary: Match via variant's product_id (safe, no cross-match risk)
                        $q->whereColumn('pv.product_id', 'p.id')
                          // PRIORITY 7: Name fallback for legacy orders without SKU/IDs
                          ->orWhere(function($nameFallback) {
                              // Only use name match when no SKU, variant_id, or product_id exists
                              $nameFallback->whereNull('li.sku')
                                           ->where(function($noIds) {
                                               $noIds->whereNull('li.variant_id')
                                                     ->orWhere('li.variant_id', '');
                                           })
                                           ->where(function($noProdId) {
                                               $noProdId->whereNull('li.product_id')
                                                        ->orWhere('li.product_id', '');
                                           })
                                           ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                          });
                    });
                })
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                // ⭐ Exclude prepared items - only show items that still need preparation
                ->where(function($q) {
                    $q->whereNull('li.preparation_status')
                      ->orWhere('li.preparation_status', '!=', 'preparing');
                });

            // Apply date filter: Default to last 20 days for performance
            // Can be overridden by passing a different date_range parameter
            if ($dateRange > 0) {
                $query->where('o.order_date', '>=', Carbon::now()->subDays($dateRange));
            } else {
                // Default: Only show orders from last 20 days to improve performance
                $query->where('o.order_date', '>=', Carbon::now()->subDays(20));
            }

            // Apply parent filters from breadcrumb navigation
            foreach ($filters as $field => $value) {
                if ($field === 'product_name') {
                    $query->where('li.name', $value);
                } elseif ($field === 'product_ids') {
                    // Accept CSV or JSON array of ids
                    if (is_string($value)) {
                        $ids = array_filter(array_map('intval', explode(',', $value)));
                    } else {
                        $ids = array_map('intval', (array)$value);
                    }
                    if (!empty($ids)) {
                        $query->where(function($q) use ($ids) {
                            $q->whereIn('li.product_id', $ids)
                              ->orWhereIn('p.id', $ids);
                        });
                    }
                } elseif ($field === 'product_id') {
                    // Ensure product filter is consistent between levels:
                    // Some line items may have product_id set, others only join via p.id
                    $query->where(function($q) use ($value) {
                        $q->where('li.product_id', $value)
                          ->orWhere('p.id', $value);
                    });
                } elseif ($field === 'product_type') {
                    $query->where(function($q) use ($value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.product_type')
                              ->orWhere('p.product_type', '');
                        } else {
                            $q->where('p.product_type', $value);
                        }
                    });
                } elseif (in_array($field, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                    $query->where(function($q) use ($field, $value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.' . $field)
                              ->orWhere('p.' . $field, '');
                        } else {
                            $q->where('p.' . $field, $value);
                        }
                    });
                } else {
                    $query->where('p.' . $field, $value);
                }
            }

            // Determine grouping field based on current level in hierarchy
            $currentField = $hierarchy[$level] ?? 'product_name';
            
            // Build select and group by based on current field
            if ($currentField === 'orders') {
                // Final level: show individual orders with customer information
                // Customer name priority: order.name -> customer.full_name -> address fields
                $query->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
                    ->select([
                        'o.order_number as group_name',
                        'o.id as order_id',
                        'o.order_status',
                        'o.order_date',
                        'o.name as order_name',
                        'o.address_first_name',
                        'o.address_last_name',
                        'c.first_name as customer_first_name',
                        'c.last_name as customer_last_name',
                        // Priority: order.name -> customer full_name -> address fields
                        \DB::raw('COALESCE(
                            NULLIF(TRIM(o.name), ""),
                            NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))), ""),
                            TRIM(CONCAT(COALESCE(o.address_first_name, ""), " ", COALESCE(o.address_last_name, "")))
                        ) as customer_full_name'),
                        \DB::raw('SUM(li.quantity) as total_quantity'),
                        // ⭐ Read-only kg figure: qty * the product's unit_weight_kg (0.5 for a
                        //    500g pack). COALESCE because the products join is a LEFT JOIN and
                        //    unmatched line items legitimately have no product row.
                        \DB::raw('SUM(li.quantity * COALESCE(p.unit_weight_kg, 1)) as total_weight'),
                        \DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                        \DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                        \DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                        // ⭐ No longer calculating preparing_quantity - prepared items are now excluded from query
                        \DB::raw('COUNT(DISTINCT li.id) as line_item_count'),
                        \DB::raw('GROUP_CONCAT(DISTINCT li.product_id) as product_ids'),
                        // ⚠️ product_ids above is MIXED (li.product_id is an EXTERNAL id on
                        //    synced orders and can collide with an unrelated internal id).
                        //    Overnight stock is keyed by the internal id, so it gets its own
                        //    list — never look stock up with the mixed one.
                        \DB::raw('GROUP_CONCAT(DISTINCT p.id) as internal_product_ids'),
                    ])
                    ->groupBy('o.id', 'o.order_number', 'o.order_status', 'o.order_date', 'o.name', 'o.address_first_name', 'o.address_last_name', 'c.first_name', 'c.last_name')
                    ->orderBy('o.order_date', 'desc');
            } elseif ($currentField === 'product_name') {
                // Group strictly by product name to merge duplicate products with same title
                // Also return the set of product_ids that share this name so drill-down can filter correctly
                $query->select([
                    'li.name as group_name',
                    \DB::raw('GROUP_CONCAT(DISTINCT COALESCE(li.product_id, p.id)) as product_ids'),
                    // Internal p.id only — the list above is mixed (li.product_id is an
                    // EXTERNAL id on synced orders) and must not be used to look up stock.
                    \DB::raw('GROUP_CONCAT(DISTINCT p.id) as internal_product_ids'),
                    \DB::raw('SUM(li.quantity) as total_quantity'),
                    \DB::raw('SUM(li.quantity * COALESCE(p.unit_weight_kg, 1)) as total_weight'),
                    \DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                    \DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                    \DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                    // ⭐ No longer calculating preparing_quantity - prepared items are now excluded from query
                    \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    \DB::raw('COUNT(DISTINCT li.id) as line_item_count')
                ])
                ->groupBy('li.name');
            } else {
                // Use COALESCE to handle null fields by showing as 'Uncategorized'
                $query->select([
                    \DB::raw("COALESCE(p.{$currentField}, 'Uncategorized') as group_name"),
                    \DB::raw('SUM(li.quantity) as total_quantity'),
                    \DB::raw('SUM(li.quantity * COALESCE(p.unit_weight_kg, 1)) as total_weight'),
                    \DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                    \DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                    \DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                    // ⭐ No longer calculating preparing_quantity - prepared items are now excluded from query
                    \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    \DB::raw('COUNT(DISTINCT CASE WHEN li.product_id IS NOT NULL THEN li.product_id END) as product_count'),
                    \DB::raw('COUNT(DISTINCT li.id) as line_item_count')
                ])
                ->groupBy(\DB::raw("COALESCE(p.{$currentField}, 'Uncategorized')"));
            }

            // Execute query with debug logging
            $sql = $query->toSql();
            Log::debug('Open Quantities SQL:', [
                'sql' => $sql, 
                'bindings' => $query->getBindings(),
                'current_level' => $level,
                'current_field' => $currentField
            ]);
            
            $results = $query
                ->orderByDesc('total_quantity')
                ->get();

            // ⭐ Per-USER display order (the same preference the mobile Quantities
            //    screen uses — one row per user, keyed by hierarchy FIELD name).
            //    When this user has set an order for THIS level it wins; otherwise
            //    nothing below changes and the page behaves exactly as before.
            //    getPrefs() never throws (missing table on prod = default order).
            $sortService = app(\App\Services\CRM\QuantitiesSortService::class);
            $userSortPrefs = $sortService->getPrefs(auth()->id());
            $userSortMode = $sortService->modeFor($userSortPrefs, $currentField);

            // Apply priority-based sorting for attribute levels
            if (!$userSortMode && in_array($currentField, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                $attributeKey = (int)str_replace('attribute_', '', $currentField);
                $priorityMap = $this->getAttributePriorityMap($attributeKey);
                
                Log::debug('Priority Sorting Debug:', [
                    'current_field' => $currentField,
                    'attribute_key' => $attributeKey,
                    'priority_map' => $priorityMap,
                    'results_before_sort' => $results->pluck('group_name')->toArray()
                ]);
                
                if (!empty($priorityMap)) {
                    $results = $results->sort(function($a, $b) use ($priorityMap) {
                        $groupA = $a->group_name ?? '';
                        $groupB = $b->group_name ?? '';
                        
                        // Get priorities (higher number = higher priority = show first)
                        $priorityA = $priorityMap[$groupA] ?? 0;
                        $priorityB = $priorityMap[$groupB] ?? 0;
                        
                        // Sort descending by priority (higher priority first)
                        if ($priorityA != $priorityB) {
                            return $priorityB - $priorityA;
                        }
                        
                        // If same priority, sort by quantity (already ordered before)
                        return ($b->total_quantity ?? 0) - ($a->total_quantity ?? 0);
                    })->values(); // Reset keys
                    
                    Log::debug('Priority Sorting Result:', [
                        'results_after_sort' => $results->pluck('group_name')->toArray()
                    ]);
                }
            }

            // ⭐ Chiller/freezer stock (what is physically in storage right now), the
            //    same figures the mobile screen shows. Category rows are matched on
            //    their attribute path and therefore include stocked products with no
            //    open orders; product rows are matched on exact internal ids.
            //    Never throws if the overnight tables are absent.
            $stockService = app(\App\Services\CRM\OvernightStockService::class);
            $isCategoryLevel = in_array($currentField, \App\Services\CRM\OvernightStockService::CATEGORY_FIELDS, true);
            $stockCatalog = $stockService->catalog();

            // ⭐ `storage_ids` = exactly which products make up this row's figure, so the
            //    breakdown popup is a local lookup against `storage_catalog` and can never
            //    disagree with the number that opened it. Omitted with `storage` itself.
            // 'orders' rows carry the internal ids too, so an order shows what is in the
            // chiller for the products on it — the same figure the mobile order card has
            // shown since Aug-12. Web computed it and then rendered nothing.
            if ($currentField === 'product_name' || $currentField === 'orders') {
                $stockMap = $stockService->map();
                if (!empty($stockMap)) {
                    foreach ($results as $row) {
                        $ids = $stockService->idsFromConcat($row->internal_product_ids ?? null);
                        $storage = $stockService->sumFor($stockMap, $ids);
                        if ($storage !== null) {
                            $row->storage = $storage;
                            $row->storage_ids = $stockService->stockedIds($stockMap, $ids);
                        }
                    }
                }
            } elseif ($isCategoryLevel) {
                if (!empty($stockCatalog)) {
                    foreach ($results as $row) {
                        $rowFilters = array_merge($filters, [$currentField => $row->group_name ?? '']);
                        $storage = $stockService->sumForCategory($stockCatalog, $rowFilters);
                        if ($storage !== null) {
                            $row->storage = $storage;
                            $row->storage_ids = $stockService->matchCategoryIds($stockCatalog, $rowFilters) ?? [];
                        }
                    }
                }
            }

            // The user's own order for this level, applied last so it is the final word.
            // Never runs at the 'orders' level (modeFor() returns null there).
            if ($userSortMode) {
                $results = collect($sortService->sortRows(
                    $results->all(),
                    $currentField,
                    $userSortPrefs,
                    fn ($row) => $row->group_name ?? '',
                    fn ($row) => (float) ($row->total_quantity ?? 0)
                ))->values();
            }

            // Get sample line item data to understand the join
            $sampleLineItems = \DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->select('li.product_id', 'li.variant_id', 'li.name', 'o.order_number')
                ->whereIn('o.order_number', ['15890', '15888', '15872'])
                ->limit(5)
                ->get();
            
            Log::debug('Open Quantities Results:', [
                'count' => $results->count(),
                'sample_results' => $results->take(5)->toArray(),
                'sample_line_items' => $sampleLineItems->toArray(),
                'all_group_names' => $results->pluck('group_name')->unique()->toArray(),
                'note' => 'Multiple join paths attempted'
            ]);

            // Calculate totals for summary
            $totalQuantity = $results->sum('total_quantity');
            $totalWeight = round((float) $results->sum('total_weight'), 2);
            $totalOrders = \DB::table('t_crm_prod_order')
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->whereNotIn('order_status', $excludedStatuses)
                ->when($dateRange > 0, function($q) use ($dateRange) {
                    $q->where('order_date', '>=', Carbon::now()->subDays($dateRange));
                })
                ->count();

            // Check if we can drill down further
            $hasNextLevel = isset($hierarchy[$level + 1]);

            return response()->json([
                'success' => true,
                'data' => $results,
                'summary' => [
                    'total_quantity' => $totalQuantity,
                    'total_weight' => $totalWeight,
                    'sort_mode' => $userSortMode, // null = default order (quantity)
                    'total_orders' => $totalOrders,
                    'category_count' => $results->count(),
                    'current_level' => $level,
                    'current_field' => $currentField,
                    'has_next_level' => $hasNextLevel
                ],
                'hierarchy' => $hierarchy,
                // ⭐ Everything physically in the chiller/freezer right now — sent once
                //    per response so every breakdown popup on the page is a local lookup.
                'storage_catalog' => $stockService->catalogRows(),
                // Ids in scope for THIS view. Rows carry `storage_ids`; anything in scope
                // but on no row is what the "also in storage" bar reports. At the root
                //    there is no category filter, so the scope is the whole room.
                'storage_scope_ids' => $stockService->matchCategoryIds($stockCatalog, $filters)
                    ?? array_map('intval', array_keys($stockCatalog)),
                // The one figure guaranteed to equal what is in the room: a category with
                // no open orders has no row, so per-row storage can never add up to this.
                'storage_total' => $stockService->grandTotal(),
            ]);

        } catch (\Exception $e) {
            Log::error('Open quantities data error: ' . $e->getMessage(), [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'request_params' => [
                    'hierarchy' => $request->get('hierarchy'),
                    'level' => $request->get('level'),
                    'filters' => $request->get('filters'),
                    'date_range' => $request->get('date_range')
                ]
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch quantity data: ' . $e->getMessage(),
                'error_details' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Helper: Read attribute labels from JSON file
     */
    private function getAttributeLabels(): array
    {
        $path = storage_path('app/private/attribute_labels.json');
        $defaults = [
            '1' => 'Category Level 1',
            '2' => 'Category Level 2',
            '3' => 'Category Level 3'
        ];
        
        if (!file_exists($path)) {
            return $defaults;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true) ?: [];
        
        // Normalize to ensure string keys
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $data[$stringKey] ?? $data[$key] ?? $defaults[$stringKey];
        }
        
        return $normalized;
    }

    /**
     * Handle payment method change for delivered order
     * Reverses old ledger entry and creates new one with correct payment method
     * 
     * @param OrderModel $order
     * @param \App\Models\FIN\LedgerModel $oldLedger
     * @param string $newPaymentMethod
     * @return array
     */
    private function handlePaymentMethodChange($order, $oldLedger, $newPaymentMethod, ?string $previousPaymentMethod = null)
    {
        \DB::beginTransaction();

        try {
            // The caller has already written the NEW method onto the order, so
            // reading it back here used to log "changed from 'online' to 'online'".
            // Prefer the value the caller captured before the update.
            $oldPaymentMethod = $previousPaymentMethod ?? $order->payment_method;
            
            // 1. Reverse the old ledger entry
            $this->reverseLedgerEntry($oldLedger, "Payment method changed from '{$oldPaymentMethod}' to '{$newPaymentMethod}'");
            
            // 2. Clear the old ledger_transaction_id so new one can be created
            $order->ledger_transaction_id = null;
            $order->save();
            
            // 3. Temporarily update order payment method for posting
            $order->payment_method = $newPaymentMethod;
            
            // 4. Create new ledger entry with correct payment method
            $ledgerService = new \App\Services\FIN\LedgerPostingService();
            $result = $ledgerService->postInvoiceFromOrder($order);
            
            if (!$result['success']) {
                throw new \Exception("Failed to repost invoice: " . $result['message']);
            }

            // Oct-19-2026 — success WITHOUT a ledger_id means posting was skipped on
            // purpose: a SHOP customer's online order (settled in the Shop tab via
            // payments) or a Qurbani order. The reversal above is then the whole job.
            // Reading $result['ledger_id'] here used to throw, roll the reversal back
            // and leave the order saying Online while the cash invoice stayed on the
            // rider's Daily Closing (KS-36, 19-Oct).
            // ("Already posted" also comes back without an id — that one is NOT a
            // skip, so it still falls through to the throw below and rolls back.)
            if (empty($result['ledger_id']) && ($result['message'] ?? '') !== 'Already posted') {
                \DB::commit();

                return [
                    'success' => true,
                    'old_ledger_id' => $oldLedger->id,
                    'new_ledger_id' => null,
                    'skipped_message' => $result['message'] ?? null,
                ];
            }

            // 5. Add note to new ledger entry
            $newLedger = \App\Models\FIN\LedgerModel::find($result['ledger_id']);
            if ($newLedger) {
                $newLedger->comments = "Payment method changed from '{$oldPaymentMethod}' to '{$newPaymentMethod}'. Original ledger #{$oldLedger->id} reversed.";
                $newLedger->save();
            } else {
                throw new \Exception("New ledger entry was not created properly");
            }
            
            \DB::commit();
            
            return [
                'success' => true,
                'old_ledger_id' => $oldLedger->id,
                'new_ledger_id' => $newLedger->id
            ];
            
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error("Failed to handle payment method change", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Which side of the ledger a payment-method spelling lands on: 'online' or
     * 'cash'. The online set is the one LedgerPostingService::postInvoiceFromOrder
     * uses to pick the ONLINE bank account; everything else is settled in cash.
     * Two spellings in the same family are the SAME method for invoice purposes.
     */
    public static function paymentMethodFamily(?string $method): string
    {
        $m = strtolower(trim((string) $method));
        return in_array($m, ['online', 'online_payment', 'bank_transfer', 'card'], true) ? 'online' : 'cash';
    }

    /**
     * Reverse a ledger entry and update balances
     *
     * @param \App\Models\FIN\LedgerModel $ledger
     * @param string $reason
     * @return void
     */
    private function reverseLedgerEntry($ledger, $reason = 'Reversed')
    {
        $wasApproved = $ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED;
        
        // Mark ledger as reversed
        $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
        $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . "REVERSED: {$reason}";
        $ledger->save();
        
        // Reverse the balance move via the canonical engine. Self-guards on balance_updated, so it
        // also reverses a row applied at L1 (pending_l2) — fixing the old `$wasApproved` guard that
        // leaked money when an order was reversed during the L1→L2 window. Engine reverse is the
        // exact inverse of the invoice/order_payment posting (income +, holder −).
        $balancesReversed = (bool) $ledger->balance_updated;
        (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

        \Log::info("Ledger entry reversed", [
            'ledger_id' => $ledger->id,
            'was_approved' => $wasApproved,
            'balances_reversed' => $balancesReversed,
            'reason' => $reason
        ]);
    }

    /**
     * ⭐ SMART SYNC: Get sync status for recent orders
     * Used by webapp to show "Synced" or "Pending" indicators
     */
    public function syncStatus(Request $request)
    {
        try {
            // Get orders assigned in last hour (only recent ones for performance)
            $hoursBack = $request->get('hours', 1); // Configurable, default 1 hour
            
            $recentOrders = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.assigned_rider_user_id', '!=', null)
                ->where('o.updated_at', '>=', now()->subHours($hoursBack))
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.assigned_rider_user_id',
                    'u.fullname as rider_name',
                    'o.rider_sync_required',
                    'o.rider_last_sync_at',
                    'o.updated_at'
                ])
                ->orderBy('o.updated_at', 'desc')
                ->get();
            
            $orders = $recentOrders->map(function($order) {
                $lastSyncAt = $order->rider_last_sync_at;
                $timeAgo = null;
                
                if ($lastSyncAt) {
                    $syncTime = \Carbon\Carbon::parse($lastSyncAt);
                    $timeAgo = $syncTime->diffForHumans();
                }
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'rider_id' => $order->assigned_rider_user_id,
                    'rider_name' => $order->rider_name,
                    'sync_required' => (bool)$order->rider_sync_required,
                    'last_sync_at' => $lastSyncAt,
                    'sync_status' => $order->rider_sync_required ? 'pending' : 'synced',
                    'sync_time_ago' => $timeAgo
                ];
            });
            
            return response()->json([
                'success' => true,
                'orders' => $orders,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get sync status', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get sync status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ GEOCODING: Geocode customers with addresses
     * Called from Operations page to batch geocode addresses
     * GET /orders/geocode-pending
     * 
     * @param days - How many days back to look for orders (default 30, use 0 for all)
     * @param limit - How many to process per batch (default 5, max 10)
     */
    public function geocodePendingCustomers(Request $request)
    {
        try {
            $limit = min($request->get('limit', 5), 10); // Max 10 per request
            $daysBack = $request->get('days', 30); // Default last 30 days (0 = all)
            
            // Find customers that need geocoding
            // Skip: already geocoded, no address, or recently attempted (failed)
            // Priority: most recent orders first
            $query = \DB::table('t_crm_prod_customer')
                ->whereNull('geocoded_latitude')
                ->whereNotNull('address1')
                ->where('address1', '!=', '')
                // Skip customers where we attempted geocoding in the last 7 days (to avoid retrying failed ones)
                ->where(function($q) {
                    $q->whereNull('geocoded_at')
                      ->orWhere('geocoded_at', '<', now()->subDays(7));
                });
            
            // Filter by recent orders if days > 0
            if ($daysBack > 0) {
                $query->where('last_order_date', '>=', now()->subDays($daysBack));
            }
            
            $customersToGeocode = $query
                ->select('id', 'address1', 'city', 'last_order_date')
                ->orderBy('last_order_date', 'desc')
                ->limit($limit)
                ->get();
            
            $geocoded = 0;
            $failed = 0;
            $results = [];
            
            foreach ($customersToGeocode as $customer) {
                try {
                    $success = \App\Services\GeocodingService::geocodeCustomer($customer->id);
                    if ($success) {
                        $geocoded++;
                        $results[] = ['id' => $customer->id, 'status' => 'success'];
                    } else {
                        $failed++;
                        $results[] = ['id' => $customer->id, 'status' => 'failed'];
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $results[] = ['id' => $customer->id, 'status' => 'error', 'message' => $e->getMessage()];
                }
                
                // Rate limit - wait 1.1 seconds between requests (Nominatim requirement)
                if ($geocoded + $failed < count($customersToGeocode)) {
                    usleep(1100000);
                }
            }
            
            // Count remaining customers needing geocoding (excluding recently attempted failures)
            $remainingQuery = \DB::table('t_crm_prod_customer')
                ->whereNull('geocoded_latitude')
                ->whereNotNull('address1')
                ->where('address1', '!=', '')
                ->where(function($q) {
                    $q->whereNull('geocoded_at')
                      ->orWhere('geocoded_at', '<', now()->subDays(7));
                });
            
            if ($daysBack > 0) {
                $remainingQuery->where('last_order_date', '>=', now()->subDays($daysBack));
            }
            
            $remaining = $remainingQuery->count();
            
            return response()->json([
                'success' => true,
                'geocoded' => $geocoded,
                'failed' => $failed,
                'remaining' => $remaining,
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Geocoding failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Geocoding failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update line item preparation status (Web route version)
     * POST /orders/{orderId}/line-items/bulk-update-status
     */
    public function bulkUpdateLineItemStatus(Request $request, $orderId)
    {
        try {
            // Validate request
            $request->validate([
                'line_item_ids' => 'required|array',
                'line_item_ids.*' => 'required|integer',
                'preparation_status' => 'nullable|in:preparing',
            ]);
            
            $lineItemIds = $request->input('line_item_ids');
            $preparationStatus = $request->input('preparation_status');
            
            // If preparation_status is empty string or null, set to null
            if (empty($preparationStatus)) {
                $preparationStatus = null;
            }
            
            // Only allow updates for regular orders (not Shopify)
            $order = OrderModel::with('lineItems')->find($orderId);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or not eligible for preparation status updates'
                ], 404);
            }
            
            // Check if order is open (not delivered/completed/cancelled)
            $closedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            if (in_array($order->order_status, $closedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update preparation status for closed orders'
                ], 400);
            }
            
            // Update line items + handle inventory deduction/restoration
            $updated = 0;
            $inventoryDeducted = 0;
            $inventoryRestored = 0;
            foreach ($lineItemIds as $lineItemId) {
                $lineItem = $order->lineItems->where('id', $lineItemId)->first();
                if ($lineItem) {
                    $oldStatus = $lineItem->preparation_status;
                    $lineItem->preparation_status = $preparationStatus;
                    $lineItem->updated_by = auth()->id();
                    $lineItem->save();
                    $updated++;

                    // ⭐ INVENTORY: Deduct when marking as prepared
                    if ($preparationStatus === 'preparing' && $oldStatus !== 'preparing') {
                        if ($lineItem->deductInventory()) {
                            $inventoryDeducted++;
                        }
                    }
                    // ⭐ INVENTORY: Restore when un-marking as prepared
                    elseif ($preparationStatus === null && $oldStatus === 'preparing') {
                        if ($lineItem->restoreInventory()) {
                            $inventoryRestored++;
                        }
                    }
                }
            }
            
            // Get updated counts (refresh from DB)
            $order->load('lineItems');
            $totalItems = $order->lineItems->count();
            $preparingCount = $order->lineItems->where('preparation_status', 'preparing')->count();
            
            return response()->json([
                'success' => true,
                'message' => "Updated {$updated} line item(s)",
                'updated_count' => $updated,
                'preparing_count' => $preparingCount,
                'total_items' => $totalItems,
                'inventory_deducted' => $inventoryDeducted,
                'inventory_restored' => $inventoryRestored,
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to bulk update line item status', [
                'order_id' => $orderId,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update line items: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk mark line items as prepared for multiple orders
     * POST /orders/bulk-mark-prepared
     */
    public function bulkMarkOrdersAsPrepared(Request $request)
    {
        try {
            \Log::info('Bulk mark prepared - Request received', [
                'user_id' => auth()->id(),
                'user' => auth()->user() ? auth()->user()->email : 'not authenticated',
                'order_ids' => $request->input('order_ids'),
                'preparation_status' => $request->input('preparation_status'),
            ]);
            
            // Validate request
            $request->validate([
                'order_ids' => 'required|array',
                'order_ids.*' => 'required|integer',
                'preparation_status' => 'nullable|in:preparing',
                'product_ids' => 'sometimes', // CSV string or array; optional
                'product_id' => 'sometimes|integer|nullable', // Single product ID; optional
                'product_name' => 'sometimes|string|nullable'
            ]);
            
            $orderIds = $request->input('order_ids');
            $preparationStatus = $request->input('preparation_status');
            $productIdsInput = $request->input('product_ids');
            $productIdSingle = $request->input('product_id');
            $productNameFilter = $request->input('product_name');
            
            // Normalize product ids (accept CSV string, array, or single ID)
            $productIds = [];
            if (!empty($productIdsInput)) {
                if (is_string($productIdsInput)) {
                    $productIds = array_filter(array_map('intval', explode(',', $productIdsInput)));
                } elseif (is_array($productIdsInput)) {
                    $productIds = array_filter(array_map('intval', $productIdsInput));
                }
            }
            // Also accept single product_id parameter
            if (!empty($productIdSingle)) {
                $productIds[] = intval($productIdSingle);
                $productIds = array_unique($productIds);
            }
            
            // If preparation_status is empty string or null, set to null
            if (empty($preparationStatus)) {
                $preparationStatus = null;
            }
            
            $totalUpdated = 0;
            $ordersUpdated = 0;
            
            // Process each order
            foreach ($orderIds as $orderId) {
                $order = OrderModel::with('lineItems')->find($orderId);
                
                if (!$order) {
                    continue; // Skip invalid orders
                }
                
                // Check if order is open (not delivered/completed/cancelled)
                $closedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
                if (in_array($order->order_status, $closedStatuses)) {
                    continue; // Skip closed orders
                }
                
                // Check if order is from Shopify (skip Shopify orders)
                if ($order->external_source === 'shopify') {
                    continue;
                }
                
                // Determine which line items to update:
                $lineItemsQuery = $order->lineItems()->newQuery();
                
                // Apply product filters with proper AND logic
                if (!empty($productIds) || !empty($productNameFilter)) {
                    $lineItemsQuery->where(function($q) use ($productIds, $productNameFilter) {
                if (!empty($productIds)) {
                            $q->whereIn('product_id', $productIds);
                }
                if (!empty($productNameFilter)) {
                            // Use AND condition, not OR
                            if (!empty($productIds)) {
                                $q->where('name', $productNameFilter);
                            } else {
                                $q->where('name', $productNameFilter);
                }
                        }
                    });
                    $lineItemsToUpdate = $lineItemsQuery->get();
                } else {
                // If no product filter was provided, update all
                    $lineItemsToUpdate = $order->lineItems;
                }

                $updatedInOrder = 0;
                $deductedInOrder = 0;
                $restoredInOrder = 0;
                foreach ($lineItemsToUpdate as $lineItem) {
                    $oldStatus = $lineItem->preparation_status;
                    $lineItem->preparation_status = $preparationStatus;
                    if (auth()->id()) {
                        $lineItem->updated_by = auth()->id();
                    }
                    $lineItem->save();
                    $updatedInOrder++;

                    // ⭐ INVENTORY: Deduct when marking as prepared
                    if ($preparationStatus === 'preparing' && $oldStatus !== 'preparing') {
                        if ($lineItem->deductInventory()) {
                            $deductedInOrder++;
                        }
                    }
                    // ⭐ INVENTORY: Restore when un-marking as prepared
                    elseif ($preparationStatus === null && $oldStatus === 'preparing') {
                        if ($lineItem->restoreInventory()) {
                            $restoredInOrder++;
                        }
                    }
                }
                
                if ($updatedInOrder > 0) {
                    \Log::debug('Bulk mark prepared - Order processed', [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'items_updated' => $updatedInOrder,
                        'items_inventory_deducted' => $deductedInOrder,
                        'items_inventory_restored' => $restoredInOrder,
                        'total_items_in_order' => $order->lineItems->count(),
                        'product_ids_filter' => $productIds,
                        'product_name_filter' => $productNameFilter,
                    ]);
                    $totalUpdated += $updatedInOrder;
                    $ordersUpdated++;
                }
            }
            
            \Log::info('Bulk mark prepared - Success', [
                'user_id' => auth()->id(),
                'total_updated' => $totalUpdated,
                'orders_updated' => $ordersUpdated,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Updated {$totalUpdated} line item(s) in {$ordersUpdated} order(s)",
                'total_updated' => $totalUpdated,
                'orders_updated' => $ordersUpdated,
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to bulk mark orders as prepared', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get global open quantities settings
     * Returns hierarchy levels and status filters that apply to all users
     */
    public function getOpenQuantitiesSettings(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user->hasPermission('view_open_quantities')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view settings.'
                ], 403);
            }

            // Fetch settings from database
            $hierarchySetting = \DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'hierarchy_levels')
                ->first();
            
            $statusSetting = \DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'excluded_statuses')
                ->first();

            // Check if user has Taimur role (by role name, not ID)
            $canEditSettings = $user->roles()
                ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                ->exists();

            // ⭐ Fetch ALL active statuses from database dynamically
            $allStatuses = \App\Models\CRM\OrderStatusMaster::active()
                ->ordered()
                ->get()
                ->map(function($status) {
                    // Map color_class to simple color name for frontend
                    $colorMap = [
                        'bg-amber-100' => 'amber',
                        'bg-yellow-100' => 'yellow',
                        'bg-orange-100' => 'orange',
                        'bg-blue-100' => 'blue',
                        'bg-violet-100' => 'violet',
                        'bg-green-100' => 'green',
                        'bg-red-100' => 'red',
                        'bg-purple-100' => 'purple',
                        'bg-gray-100' => 'gray',
                    ];
                    
                    $color = 'gray'; // default
                    if ($status->color_class) {
                        foreach ($colorMap as $cssClass => $colorName) {
                            if (strpos($status->color_class, $colorName) !== false) {
                                $color = $colorName;
                                break;
                            }
                        }
                    }
                    
                    return [
                        'code' => $status->status_code,
                        'name' => $status->status_name,
                        'color' => $color,
                        'is_final' => $status->is_final ?? false,
                    ];
                })
                ->toArray();

            // If no statuses in database, use sensible defaults
            if (empty($allStatuses)) {
                $allStatuses = [
                    ['code' => 'new', 'name' => 'New', 'color' => 'amber', 'is_final' => false],
                    ['code' => 'pending', 'name' => 'Pending', 'color' => 'yellow', 'is_final' => false],
                    ['code' => 'on_hold', 'name' => 'On Hold', 'color' => 'orange', 'is_final' => false],
                    ['code' => 'processing', 'name' => 'Processing', 'color' => 'blue', 'is_final' => false],
                    ['code' => 'out_for_delivery', 'name' => 'Out for Delivery', 'color' => 'violet', 'is_final' => false],
                    ['code' => 'delivered', 'name' => 'Delivered', 'color' => 'green', 'is_final' => true],
                    ['code' => 'completed', 'name' => 'Completed', 'color' => 'green', 'is_final' => true],
                    ['code' => 'cancelled', 'name' => 'Cancelled', 'color' => 'red', 'is_final' => true],
                    ['code' => 'refunded', 'name' => 'Refunded', 'color' => 'purple', 'is_final' => true],
                ];
            }

            return response()->json([
                'success' => true,
                'settings' => [
                    'hierarchy_levels' => $hierarchySetting ? json_decode($hierarchySetting->setting_value, true) : ['product_type', 'product_name', 'orders'],
                    'excluded_statuses' => $statusSetting ? json_decode($statusSetting->setting_value, true) : ['delivered', 'completed', 'cancelled', 'refunded']
                ],
                'all_statuses' => $allStatuses, // ⭐ New: Dynamic statuses from database
                'can_edit' => $canEditSettings,
                'updated_at' => $hierarchySetting ? $hierarchySetting->updated_at : null,
                'updated_by' => $hierarchySetting ? $hierarchySetting->updated_by_user_id : null
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get open quantities settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save global open quantities settings
     * Only users with Taimur role can save settings
     */
    /**
     * Receipt printout field config — read (for the orders-page "Receipt fields" modal).
     */
    public function getReceiptPrintConfig()
    {
        $raw = \DB::table('t_sys_config')->where('id', 1)->value('receipt_print_config');
        $decoded = $raw ? json_decode($raw, true) : null;
        return response()->json(['config' => is_array($decoded) ? $decoded : (object) []]);
    }

    /**
     * Receipt printout field config — save (manager-only). Whitelisted boolean keys only, so a
     * malformed request can never inject arbitrary data into the config blob.
     */
    public function saveReceiptPrintConfig(Request $request)
    {
        try {
            $user = auth()->user();
            $isManager = $user && $user->roles()
                ->whereRaw('LOWER(urole_name) IN (?, ?)', ['admin', 'taimur'])
                ->exists();
            if (!$isManager) {
                return response()->json(['success' => false, 'message' => 'Only a manager role can change receipt settings.'], 403);
            }
            $boolKeys = ['show_logo', 'show_prices', 'show_phone', 'show_address', 'show_disclaimer'];
            $out = [];
            foreach ($boolKeys as $k) {
                $out[$k] = $request->boolean($k) ? 1 : 0;
            }
            // Editable text lines: strip control chars, trim, cap length. Empty string is a
            // valid value meaning "hide this line".
            $textKeys = ['store_name' => 40, 'tagline_text' => 40, 'contact_line' => 48, 'footer_text' => 120, 'disclaimer_text' => 160];
            foreach ($textKeys as $k => $max) {
                $v = (string) $request->input($k, '');
                $v = preg_replace('/[\x00-\x1F\x7F]+/', '', $v);
                $out[$k] = mb_substr(trim($v), 0, $max);
            }
            // Numeric: QR module size in printer dots. The ESC/POS QR command (GS ( k fn 67)
            // accepts 1..16 ONLY — out of range and the printer silently falls back to a tiny
            // default QR. Clamped 8..16 here so a malformed request can never break the label.
            // 16 = current (largest) size; 12 = the pre-Aug-2026 size, the fallback if the
            // BT-600M mishandles large modules. Absent => 16, matching the app's own default.
            $qr = (int) $request->input('qr_module_size', 16);
            $out['qr_module_size'] = max(8, min(16, $qr > 0 ? $qr : 16));
            // Who draws the QR. 'image' (default) = the app draws it and sends a picture, which is
            // NOT bound by the printer firmware's module-size cap; 'printer' = the old ESC/POS QR
            // command. Escape hatch only — anything unrecognised falls back to 'image'.
            $out['qr_mode'] = $request->input('qr_mode') === 'printer' ? 'printer' : 'image';
            \DB::table('t_sys_config')->where('id', 1)->update(['receipt_print_config' => json_encode($out, JSON_UNESCAPED_UNICODE)]);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Could not save settings'], 500);
        }
    }

    /**
     * Scan help (Aug-2026) — riders waiting for a manager to approve a short delivery, for the
     * orders-page banner. Shares ScanHelpService with store mode so the two surfaces cannot drift.
     *
     * ⚠ The route for this is deliberately NOT /settings- or /config-shaped: the host's StackProtect
     *   bot filter challenges those URLs before they reach Laravel, which is exactly why the
     *   delivery-scan settings modal has been silently failing on prod. Both candidate paths were
     *   curl-verified against prod (Laravel's own 404 = passes) before this code was written.
     */
    public function scanHelpPending()
    {
        try {
            $svc = new \App\Services\Riders\ScanHelpService();
            $canApprove = $svc->canApprove(auth()->user());
            return response()->json([
                'success'     => true,
                'can_approve' => $canApprove,
                'requests'    => $canApprove ? $svc->pending() : [],
            ]);
        } catch (\Exception $e) {
            // A banner must never be able to break the page that polls it.
            return response()->json(['success' => true, 'can_approve' => false, 'requests' => []]);
        }
    }

    /**
     * Scan help — approve or deny from the orders page. Authority is ScanHelpService::canApprove.
     */
    public function scanHelpDecide(Request $request)
    {
        try {
            $res = (new \App\Services\Riders\ScanHelpService())->decide(
                (int) $request->input('order_id'),
                (string) $request->input('decision', ''),
                auth()->user()
            );
            return response()->json([
                'success' => $res['ok'],
                'status'  => $res['status'] ?? null,
                'code'    => $res['code'] ?? null,
                'message' => $res['message'] ?? null,
            ], $res['ok'] ? 200 : (($res['code'] ?? '') === 'forbidden' ? 403 : 422));
        } catch (\Exception $e) {
            \Log::error('scanHelpDecide (web) failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the decision'], 500);
        }
    }

    /**
     * Phase 3 delivery-scan settings (orders-page operations toggle) — read the two flags.
     */
    public function getDeliveryScanSettings()
    {
        $cfg = \DB::table('t_sys_config')->where('id', 1)->first();
        return response()->json([
            'require_delivery_scan' => $cfg ? (int) ($cfg->require_delivery_scan ?? 0) : 0,
            'allow_delivery_scan_bypass' => $cfg ? (int) ($cfg->allow_delivery_scan_bypass ?? 0) : 0,
            // Enh-1: store-side dispatch hand-over banner (default off). Separate switch from the
            // rider delivery scan above — this only controls the "N not scanned" awareness banner.
            'dispatch_scan_banner_enabled' => $cfg ? (int) ($cfg->dispatch_scan_banner_enabled ?? 0) : 0,
        ]);
    }

    /**
     * Phase 3 delivery-scan settings — save the two flags. This is the owner's on/off +
     * bypass control for the rider package scan (default off = nothing changes for riders).
     */
    public function saveDeliveryScanSettings(Request $request)
    {
        try {
            // Manager-only: this switch gates riders' ability to mark orders delivered,
            // so ordinary staff must not be able to flip it.
            // ⚠ Aug-2026: 'management' added — there is NO role literally named 'admin' in
            //   t_sys_role (the admin roles are 'Management' and 'Taimur'), so the original
            //   ['admin','taimur'] check silently matched ONLY Taimur and locked Shabib out of
            //   his own settings modal. 'admin' kept in case such a role is ever created.
            $user = auth()->user();
            $isManager = $user && $user->roles()
                ->whereRaw('LOWER(urole_name) IN (?, ?, ?)', ['admin', 'management', 'taimur'])
                ->exists();
            if (!$isManager) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a manager role can change delivery scan settings.',
                ], 403);
            }

            \DB::table('t_sys_config')->where('id', 1)->update([
                'require_delivery_scan' => $request->boolean('require_delivery_scan') ? 1 : 0,
                'allow_delivery_scan_bypass' => $request->boolean('allow_delivery_scan_bypass') ? 1 : 0,
            ]);
            // Enh-1 banner flag in its OWN update: if the column migration hasn't run yet, only
            // this write fails silently — the two scan toggles above still save normally.
            try {
                \DB::table('t_sys_config')->where('id', 1)->update([
                    'dispatch_scan_banner_enabled' => $request->boolean('dispatch_scan_banner_enabled') ? 1 : 0,
                ]);
            } catch (\Exception $e) {}
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Could not save settings'], 500);
        }
    }

    public function saveOpenQuantitiesSettings(Request $request)
    {
        try {
            $user = auth()->user();

            // Check permission
            if (!$user->hasPermission('view_open_quantities')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to manage settings.'
                ], 403);
            }

            // Check if user has Taimur role (by role name, not ID)
            $hasTaimurRole = $user->roles()
                ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                ->exists();
            if (!$hasTaimurRole) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Taimur role can modify these settings.'
                ], 403);
            }

            $hierarchyLevels = $request->input('hierarchy_levels');
            $excludedStatuses = $request->input('excluded_statuses');

            // Validate hierarchy levels
            if ($hierarchyLevels) {
                if (!is_array($hierarchyLevels)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Hierarchy levels must be an array.'
                    ], 400);
                }

                // Must end with 'orders'
                if (end($hierarchyLevels) !== 'orders') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Hierarchy levels must end with "orders".'
                    ], 400);
                }

                // Update or insert hierarchy setting
                \DB::table('t_crm_open_quantities_settings')
                    ->updateOrInsert(
                        ['setting_key' => 'hierarchy_levels'],
                        [
                            'setting_value' => json_encode($hierarchyLevels),
                            'setting_type' => 'hierarchy',
                            'updated_by_user_id' => $user->id,
                            'updated_at' => now()
                        ]
                    );
            }

            // Validate excluded statuses
            if ($excludedStatuses) {
                if (!is_array($excludedStatuses)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Excluded statuses must be an array.'
                    ], 400);
                }

                // Update or insert status setting
                \DB::table('t_crm_open_quantities_settings')
                    ->updateOrInsert(
                        ['setting_key' => 'excluded_statuses'],
                        [
                            'setting_value' => json_encode($excludedStatuses),
                            'setting_type' => 'status_filter',
                            'updated_by_user_id' => $user->id,
                            'updated_at' => now()
                        ]
                    );
            }

            // Bust the central status-rule cache so the new exclusions apply immediately
            // (the rule service memoises the excluded list for 60s).
            app(\App\Services\CRM\OrderStatusRuleService::class)->bustCache();

            Log::info('Open quantities settings updated by user: ' . $user->fullname, [
                'user_id' => $user->id,
                'hierarchy_levels' => $hierarchyLevels,
                'excluded_statuses' => $excludedStatuses
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Settings saved successfully.',
                'settings' => [
                    'hierarchy_levels' => $hierarchyLevels,
                    'excluded_statuses' => $excludedStatuses
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to save open quantities settings: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get priority map for attribute categories
     * Reads rules from attribute_auto_rules.json and creates a map of category name => priority
     * If multiple rules have the same category, uses the HIGHEST priority
     */
    private function getAttributePriorityMap(int $attributeKey): array
    {
        try {
            $filePath = storage_path('app/private/attribute_auto_rules.json');
            
            if (!file_exists($filePath)) {
                Log::warning('Attribute rules file not found', ['path' => $filePath]);
                return [];
            }
            
            $json = file_get_contents($filePath);
            $allRules = json_decode($json, true) ?: [];
            $rules = $allRules[(string)$attributeKey] ?? [];
            
            $priorityMap = [];
            foreach ($rules as $rule) {
                $group = trim((string)($rule['group'] ?? ''));
                $priority = (int)($rule['priority'] ?? 0);
                
                if ($group !== '') {
                    // Keep the HIGHEST priority for each category
                    if (!isset($priorityMap[$group]) || $priority > $priorityMap[$group]) {
                        $priorityMap[$group] = $priority;
                    }
                }
            }
            
            Log::debug('Loaded priority map for attribute ' . $attributeKey, [
                'priority_map' => $priorityMap,
                'total_rules' => count($rules)
            ]);
            
            return $priorityMap;
        } catch (\Exception $e) {
            \Log::error('Failed to read attribute priority map: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Change payment method for an order (Quick Change from orders list)
     * ⭐ NOTE: For delivered orders with ledger entries, this should be blocked
     *    and the user should use the Edit Order form which properly handles ledger changes
     */
    public function changePaymentMethod(Request $request)
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer|exists:t_crm_prod_order,id',
                'payment_method' => 'required|string|in:cash,online',
                'notes' => 'nullable|string|max:500',
                // Aug-2026 — "also remember this for the customer" tick in the
                // quick-change modal. Same flag name and meaning as the one on
                // both create-order forms and the mobile store Edit modal.
                'set_default_payment_method' => 'nullable|boolean',
            ]);

            // ⚠️ Shopify-approval-queue guard (Jul-2026): this endpoint resolves the
            // id against t_crm_prod_order ONLY. Staging ids overlap with production
            // ids, so a staging order's id passed here would pass the exists: rule
            // (the COLLIDING production id exists) and change the WRONG order's
            // payment method. Business rule: payment method is locked while an
            // order sits unconverted in the Shopify queue — change it after
            // conversion. The frontend hides the chip in Shopify mode; this is the
            // server-side backstop.
            if (strtolower((string) $request->input('source', '')) === 'shopify') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method is locked while the order is in the Shopify approval queue. Convert the order first, then change it.',
                    'error_type' => 'shopify_queue_locked'
                ], 422);
            }

            $order = OrderModel::findOrFail($validated['order_id']);
            $oldMethod = $order->payment_method;
            $newMethod = $validated['payment_method'];
            
            // ⭐ SECURITY CHECK: Block payment method change for delivered orders with ledger entries
            // These must go through the Edit Order form which properly handles ledger reversal/recreation
            if ($order->ledger_transaction_id && in_array($order->order_status, ['delivered', 'completed'])) {
                \Log::warning('Attempted quick payment method change on delivered order with ledger', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'ledger_id' => $order->ledger_transaction_id,
                    'user_id' => auth()->id()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot quick-change payment method for delivered orders with ledger entries. Please use Edit Order to properly handle the ledger update.',
                    'error_type' => 'delivered_with_ledger'
                ], 422);
            }

            // Map to existing system values (reuse web app conventions)
            // - Cash → cash_on_delivery
            // - Online → online
            $mappedMethod = $newMethod === 'cash' ? 'cash_on_delivery' : 'online';

            // Update order payment method only (no history table dependency)
            $order->payment_method = $mappedMethod;
            $order->save();

            \Log::info('Payment method changed (quick change)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_method' => $oldMethod,
                'new_method' => $mappedMethod,
                'user_id' => auth()->id()
            ]);

            // Aug-2026 — remember it on the CUSTOMER as well, when ticked.
            // Resolved from the order's own customer_id (never a client-supplied
            // id) and written through the single helper, so this surface stamps
            // attribution exactly like the other three. Non-fatal: remembering a
            // preference must never fail a payment-method change.
            $defaultSaved = null;
            if ($order->customer_id
                && filter_var($request->input('set_default_payment_method', false), FILTER_VALIDATE_BOOLEAN)) {
                try {
                    $stored = \App\Models\CRM\CustomerModel::setDefaultPaymentMethod(
                        $order->customer_id, $mappedMethod, auth()->id()
                    );
                    if ($stored !== false) {
                        $defaultSaved = $stored;
                        \Log::info('Customer default payment method set from quick change', [
                            'customer_id' => $order->customer_id,
                            'order_id' => $order->id,
                            'default_payment_method' => $stored,
                            'user_id' => auth()->id(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Failed to set customer default from quick change (non-fatal)', [
                        'customer_id' => $order->customer_id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'order' => $order,
                'default_payment_method_saved' => $defaultSaved,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to change payment method: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment method change timeline for an order
     */
    public function getPaymentMethodTimeline($orderId)
    {
        // ⚠⚠ t_crm_order_payment_method_history DOES NOT EXIST on every environment
        // — it is absent on the local replica, and changePaymentMethod() is written
        // with "no history table dependency" for exactly that reason. Until now the
        // missing table threw and dropped this WHOLE method into its catch, so the
        // endpoint always answered success:false with no data. The history query is
        // therefore optional on its own; a missing table simply means "no history".
        //
        // That isolation is load-bearing for the block below: the customer default
        // must still reach the modal on an environment that has no history table,
        // which is every environment we can currently see.
        $history = [];
        try {
            if (\Schema::hasTable('t_crm_order_payment_method_history')) {
                $history = \DB::table('t_crm_order_payment_method_history')
                    ->where('order_id', $orderId)
                    ->orderBy('changed_at', 'desc')
                    ->limit(10)
                    ->get();
            }
        } catch (\Throwable $e) {
            \Log::debug('Payment method timeline unavailable: ' . $e->getMessage());
        }

        // Aug-2026 — the quick-change modal also shows "the customer's default is X,
        // set by Y" before you overwrite anything. It rides THIS response rather
        // than a second request, because the modal already awaits this one on open.
        //
        // ⚠ Resolved against the PRODUCTION order table only. A Shopify-queue id
        // would collide with an unrelated live order, so when the caller says it is
        // in Shopify mode we return no default rather than a stranger's.
        // changePaymentMethod() refuses those ids outright, so the modal is never
        // usable there anyway — this is the matching read-side guard.
        $customerDefault = null;
        try {
            if (strtolower((string) request()->input('source', '')) !== 'shopify') {
                $order = OrderModel::find($orderId);
                if ($order && $order->customer_id) {
                    $customer = \App\Models\CRM\CustomerModel::find($order->customer_id);
                    if ($customer) {
                        $customerDefault = \App\Models\CRM\CustomerModel::defaultPaymentMethodInfo($customer);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Customer default lookup failed for timeline: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $history,
            'customer_default' => $customerDefault,
        ]);
    }
    
    /**
     * Phase 2 L1 — per-order Activity Log (2026-07-03).
     *
     * The "who changed what" feed for one order, read straight from the audit
     * trail (t_sys_audit_log): money/ownership edits, payments and ledger actions
     * — each with the actor, source, timestamp and field-level old→new diffs.
     * Status changes are intentionally NOT here (they live in the Status History
     * section, from t_crm_order_status_history — see OrderAuditObserver).
     *
     * Fail-safe: if the audit table hasn't been created yet (PHP-first deploy),
     * returns ready:false with an empty list so the UI shows a friendly note
     * instead of erroring. Never throws.
     */
    public function getOrderActivityLog($orderId)
    {
        try {
            if (!\Schema::hasTable('t_sys_audit_log')) {
                return response()->json(['success' => true, 'ready' => false, 'entries' => []]);
            }

            $rows = \DB::table('t_sys_audit_log as a')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'a.user_id')
                ->where(function ($w) use ($orderId) {
                    $w->where('a.related_order_id', $orderId)
                      ->orWhere(function ($x) use ($orderId) {
                          $x->where('a.entity_type', 'order')->where('a.entity_id', $orderId);
                      });
                })
                ->orderByDesc('a.at')
                ->orderByDesc('a.id')
                ->select('a.at', 'a.source', 'a.action', 'a.entity_type', 'a.entity_label', 'a.changes', 'a.note', 'u.fullname as user_name')
                ->limit(200)
                ->get();

            $entries = $rows->map(function ($r) {
                $changes = null;
                if ($r->changes) {
                    $decoded = json_decode($r->changes, true);
                    if (is_array($decoded)) {
                        $changes = $decoded;
                    }
                }
                return [
                    'at'      => (string) $r->at,
                    'user'    => $r->user_name ?: '—',
                    'source'  => $r->source,
                    'action'  => $r->action,
                    'entity'  => $r->entity_type,
                    'label'   => $r->entity_label,
                    'changes' => $changes,   // {field: {old, new}}
                    'note'    => $r->note,
                ];
            })->values();

            return response()->json(['success' => true, 'ready' => true, 'entries' => $entries]);
        } catch (\Throwable $e) {
            // Never break the edit screen over the activity log.
            return response()->json(['success' => false, 'ready' => false, 'entries' => [], 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Get complete event history for an order
     * Includes: order created, status changes, ledger entries, adjustments, and order updates
     */
    public function getOrderEventHistory($orderId)
    {
        try {
            $order = OrderModel::findOrFail($orderId);
            $events = [];
            
            // 1. Order Created
            $events[] = [
                'type' => 'order_created',
                'icon' => '📦',
                'title' => 'Order Created',
                'description' => "Order #{$order->order_number} created",
                'details' => [
                    'Total' => 'Rs. ' . number_format($order->total_price, 2),
                    'Payment Method' => $order->payment_method ?? 'N/A',
                ],
                'timestamp' => $order->created_at,
                'color' => '#3b82f6' // blue
            ];
            
            // 2. Status Changes from history
            $statusHistory = \DB::table('t_crm_order_status_history as h')
                ->leftJoin('t_crm_order_status_master as s', 'h.status_code', '=', 's.status_code')
                ->leftJoin('t_sys_user as u', 'h.changed_by', '=', 'u.id')
                ->where('h.order_id', $orderId)
                ->select('h.*', 's.status_name', 'u.fullname as changed_by_name')
                ->orderBy('h.changed_at', 'asc')
                ->get();
            
            foreach ($statusHistory as $status) {
                $statusIcon = match($status->status_code) {
                    'new' => '🆕',
                    'processing' => '⚙️',
                    'on_hold' => '⏸️',
                    'out_for_delivery' => '🚚',
                    'delivered' => '✅',
                    'completed' => '🎉',
                    'cancelled' => '❌',
                    'refunded' => '💸',
                    default => '📋'
                };
                
                $events[] = [
                    'type' => 'status_change',
                    'icon' => $statusIcon,
                    'title' => 'Status: ' . ($status->status_name ?? ucfirst($status->status_code)),
                    'description' => 'Changed by ' . ($status->changed_by_name ?? 'System'),
                    'details' => $status->notes ? ['Notes' => $status->notes] : null,
                    'timestamp' => $status->changed_at,
                    'color' => match($status->status_code) {
                        'delivered', 'completed' => '#10b981', // green
                        'cancelled', 'refunded' => '#ef4444', // red
                        'processing', 'out_for_delivery' => '#f59e0b', // amber
                        default => '#6b7280' // gray
                    }
                ];
            }
            
            // 3. Ledger Entries
            $ledgers = \App\Models\FIN\LedgerModel::where('order_id', $orderId)
                ->with(['toAccount', 'createdBy'])
                ->orderBy('created_at', 'asc')
                ->get();
            
            foreach ($ledgers as $ledger) {
                $ledgerIcon = $ledger->mode === 'online' ? '🏦' : '💵';
                $statusLabel = match($ledger->approval_status) {
                    'approved' => '✓ Approved',
                    'pending', 'pending_l1' => '⏳ Pending L1',
                    'pending_l2' => '⏳ Pending L2',
                    'rejected' => '✗ Rejected',
                    'reversed' => '↩️ Reversed',
                    default => $ledger->approval_status
                };
                
                $events[] = [
                    'type' => 'ledger_created',
                    'icon' => $ledgerIcon,
                    'title' => "Ledger Entry #{$ledger->id}",
                    'description' => "Invoice posted to ledger",
                    'details' => [
                        'Mode' => ucfirst($ledger->mode),
                        'Amount' => 'Rs. ' . number_format($ledger->amount, 2),
                        'Account' => $ledger->toAccount ? $ledger->toAccount->account_name : 'N/A',
                        'Status' => $statusLabel,
                    ],
                    'timestamp' => $ledger->created_at,
                    'color' => $ledger->mode === 'online' ? '#8b5cf6' : '#10b981' // purple for online, green for cash
                ];
                
                // If ledger was updated (approval, etc.)
                if ($ledger->updated_at && $ledger->updated_at != $ledger->created_at) {
                    $events[] = [
                        'type' => 'ledger_updated',
                        'icon' => '✏️',
                        'title' => "Ledger #{$ledger->id} Updated",
                        'description' => "Status: {$statusLabel}",
                        'details' => [
                            'Current Amount' => 'Rs. ' . number_format($ledger->amount, 2),
                        ],
                        'timestamp' => $ledger->updated_at,
                        'color' => '#6b7280'
                    ];
                }
            }
            
            // 4. Ledger Adjustments
            $adjustments = \App\Models\FIN\LedgerAdjustmentModel::where('order_id', $orderId)
                ->with(['requestedBy'])
                ->orderBy('created_at', 'asc')
                ->get();
            
            foreach ($adjustments as $adj) {
                $adjStatusIcon = match($adj->adjustment_status) {
                    'approved' => '✅',
                    'pending' => '⏳',
                    'rejected' => '❌',
                    default => '📋'
                };
                
                $events[] = [
                    'type' => 'ledger_adjustment',
                    'icon' => $adjStatusIcon,
                    'title' => "Ledger Adjustment #{$adj->id}",
                    'description' => $adj->reason ?? 'Amount adjustment',
                    'details' => [
                        'Old Amount' => 'Rs. ' . number_format($adj->old_amount, 2),
                        'New Amount' => 'Rs. ' . number_format($adj->new_amount, 2),
                        'Difference' => ($adj->adjustment_amount >= 0 ? '+' : '') . 'Rs. ' . number_format($adj->adjustment_amount, 2),
                        'Status' => ucfirst($adj->adjustment_status),
                    ],
                    'timestamp' => $adj->created_at,
                    'color' => $adj->adjustment_status === 'approved' ? '#10b981' : '#f59e0b'
                ];
            }
            
            // 5. Order Updated (if different from created)
            if ($order->updated_at && $order->updated_at != $order->created_at) {
                $events[] = [
                    'type' => 'order_updated',
                    'icon' => '✏️',
                    'title' => 'Order Updated',
                    'description' => 'Order details modified',
                    'details' => [
                        'Current Total' => 'Rs. ' . number_format($order->total_price, 2),
                        'Payment Method' => $order->payment_method ?? 'N/A',
                    ],
                    'timestamp' => $order->updated_at,
                    'color' => '#6b7280'
                ];
            }
            
            // Sort all events by timestamp
            usort($events, function($a, $b) {
                return strtotime($a['timestamp']) - strtotime($b['timestamp']);
            });
            
            return response()->json([
                'success' => true,
                'order_number' => $order->order_number,
                'events' => $events
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get order event history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load event history',
                'events' => []
            ], 500);
        }
    }
}
