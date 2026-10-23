<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-app server-to-server pull endpoints (Phase 2).
 *
 * These are called by the customer-app (Vercel) backend, authenticated by the
 * `customer.app` middleware (bearer token). They are NOT customer-facing — the
 * customer-app backend resolves the customer from the mobile JWT and enforces
 * order ownership before proxying to us.
 */
class CustomerAppController extends Controller
{
    /**
     * Sentinel stored in verified_location_saved_by when the CUSTOMER (not an
     * ops/staff user) set the pin from the customer app. Single source of
     * truth lives on CustomerModel so web/ops screens render it identically.
     */
    private const CUSTOMER_PIN_SAVED_BY = CustomerModel::VERIFIED_PIN_CUSTOMER_ID;

    /**
     * Live rider tracking for an out-for-delivery order.
     *
     * GET /api/customer-app/orders/{orderNumber}/tracking
     *
     * {orderNumber} accepts any full NF number ("SH-1234", "NF-2001",
     * "QUR26-045") or a bare Shopify number ("1234" -> SH-1234), resolved the
     * same way as orderSnapshot/orderInvoice (via resolveAnyOrderNumber). A
     * lettered prefix is kept as-is, so NF-/QUR orders resolve correctly.
     * Mirrors the customer app's LiveDelivery shape (snake_case; their backend
     * maps to camelCase).
     *
     * Response semantics (match the app's expectations):
     *   200 { "tracking": {...} }  -> a live fix; render the map.
     *   200 { "tracking": null  }  -> no live fix yet; fall back to timeline.
     *   404                        -> unknown order.
     */
    public function tracking(Request $request, string $orderNumber)
    {
        try {
            $nfOrderNumber = $this->resolveAnyOrderNumber($orderNumber);

            $order = DB::table('t_crm_prod_order')
                ->where('order_number', $nfOrderNumber)
                ->select([
                    'id',
                    'order_number',
                    'order_status',
                    'customer_id',
                    'assigned_rider_user_id',
                    'delivery_priority',
                    'estimated_delivery_at',
                    // "Has this order actually been dispatched?" — see the hold-until-
                    // dispatch gate below.
                    'eta_calculated_at',
                ])
                ->first();

            if (!$order) {
                return response()->json([
                    'success'  => false,
                    'message'  => 'Order not found.',
                    'tracking' => null,
                    'reason'   => 'order_not_found',
                ], 404);
            }

            $rules        = app(\App\Services\CRM\OrderStatusRuleService::class);
            $isDispatched = !empty($order->eta_calculated_at);

            // Only meaningful while out for delivery. Anything else -> no fix.
            // ⭐ `reason` lets the customer-app dev distinguish WHY tracking is null
            // (previously every case returned an identical {tracking:null}).
            if (strtolower((string) $order->order_status) !== 'out_for_delivery') {
                return response()->json([
                    'success'  => true,
                    'tracking' => null,
                    'reason'   => 'not_out_for_delivery',
                    // Status Hub: mask aliased/held statuses here too (gate above stays on RAW status).
                    'order_status' => $rules->customerFacingStatus(
                        $order->order_number, $order->order_status, $isDispatched
                    ),
                ], 200);
            }

            // ⭐ Out for delivery on OUR side, but not yet dispatched — ops set the
            // status early and the order is still being loaded at the store. There is
            // no route, no ETA, and the rider is standing at the shop, so a live map
            // would be actively misleading. The customer app is told the held-back
            // status (`processing`) and falls back to the timeline, exactly as it does
            // for any other pre-delivery step. Deliberately keyed on the RAW status +
            // the Hub flag, not on the aliased value.
            if ($rules->masksUntilDispatch($order->order_number, $order->order_status, $isDispatched)) {
                return response()->json([
                    'success'      => true,
                    'tracking'     => null,
                    'reason'       => 'not_dispatched',
                    'order_status' => $rules->customerFacingStatus(
                        $order->order_number, $order->order_status, $isDispatched
                    ),
                ], 200);
            }

            if (empty($order->assigned_rider_user_id)) {
                return response()->json([
                    'success'  => true,
                    'tracking' => null,
                    'reason'   => 'no_rider_assigned',
                ], 200);
            }

            // Latest rider GPS fix within the freshness window.
            $staleness = (int) config('customer_app.tracking_gps_staleness_minutes', 30);
            $fix = DB::table('t_ops_rider_location')
                ->where('user_id', $order->assigned_rider_user_id)
                ->where('captured_at', '>=', now()->subMinutes($staleness))
                ->orderBy('captured_at', 'desc')
                ->first();

            if (!$fix || $fix->latitude === null || $fix->longitude === null) {
                // No fresh rider position -> app shows "tracking unavailable".
                // Most common real cause: the rider's GPS went stale (device snooze).
                return response()->json([
                    'success'  => true,
                    'tracking' => null,
                    'reason'   => 'gps_stale',
                    'staleness_window_minutes' => $staleness,
                ], 200);
            }

            // Customer drop point (verified pin preferred, geocoded fallback).
            $destination = $this->resolveDestination($order->customer_id);
            if (!$destination) {
                return response()->json([
                    'success'  => true,
                    'tracking' => null,
                    'reason'   => 'no_destination',
                ], 200);
            }

            $tracking = [
                'order_number'    => $this->stripPrefix($order->order_number),
                'nf_order_number' => $order->order_number,
                'rider' => [
                    'lat' => (float) $fix->latitude,
                    'lng' => (float) $fix->longitude,
                ],
                'destination' => [
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                ],
                'stops_away' => $this->stopsAway($order),
                'eta'        => $order->estimated_delivery_at
                    ? \Carbon\Carbon::parse($order->estimated_delivery_at)->toIso8601String()
                    : null,
                'updated_at' => $fix->captured_at
                    ? \Carbon\Carbon::parse($fix->captured_at)->toIso8601String()
                    : now()->toIso8601String(),
            ];

            return response()->json(['success' => true, 'tracking' => $tracking], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::tracking failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json([
                'success'  => false,
                'message'  => 'Tracking lookup failed.',
                'tracking' => null,
                'reason'   => 'error',
            ], 500);
        }
    }

    /**
     * Full order snapshot (Phase 3).
     *
     * GET /api/customer-app/orders/{orderNumber}
     *
     * {orderNumber} may be a bare Shopify number ("1234" -> assumed SH-1234)
     * or any full NF number ("SH-1234", "NF-2001", "QUR26-045"). The customer
     * app calls this once on the `accepted` webhook (to swap the Shopify view
     * for the NF order) and on pull-to-refresh.
     *
     *   200 { "order": {...} }   -> found
     *   404 { "order": null  }   -> unknown order
     */
    public function orderSnapshot(Request $request, string $orderNumber)
    {
        try {
            $nfOrderNumber = $this->resolveAnyOrderNumber($orderNumber);

            // Columns shared by both the live and history order tables.
            $snapshotCols = [
                'id', 'order_number', 'order_status', 'order_date',
                'currency', 'payment_method',
                'subtotal_price', 'discount_total', 'shipping_total',
                'total_tax', 'tip_amount', 'total_price',
                'name', 'address_phone', 'address_line1', 'address_line2',
                'address_city', 'address_province', 'address_postal_code',
                'address_country',
            ];

            // Live order first — it carries estimated_delivery_at for the ETA window
            // and eta_calculated_at for the hold-until-dispatch rule.
            $order = DB::table('t_crm_prod_order')
                ->where('order_number', $nfOrderNumber)
                ->select(array_merge($snapshotCols, ['estimated_delivery_at', 'eta_calculated_at']))
                ->first();
            $isHistory = false;

            // Fall back to a historical (CSV-imported) order so the customer can
            // open older orders for detail. History order numbers are unique and
            // don't collide with live ones, so matching by the raw or resolved
            // number is safe. History rows have no live ETA.
            if (!$order) {
                $rawNumber = ltrim(trim(urldecode($orderNumber)), '#');
                $order = DB::table('t_crm_history_order')
                    ->whereIn('order_number', array_values(array_unique([$nfOrderNumber, $rawNumber])))
                    ->select($snapshotCols)
                    ->first();
                $isHistory = (bool) $order;
            }

            if (!$order) {
                return response()->json(['success' => false, 'order' => null], 404);
            }

            $items = DB::table($isHistory ? 't_crm_history_order_line_item' : 't_crm_prod_order_line_item')
                ->where('order_id', $order->id)
                ->select(['sku', 'name', 'quantity', 'unit_price', 'line_total'])
                ->get()
                ->map(fn ($i) => [
                    'sku'        => $i->sku,
                    'name'       => $i->name,
                    'quantity'   => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                    'line_total' => (float) $i->line_total,
                ])->values();

            $payload = [
                'order_number'    => $this->stripShopifyPrefix($order->order_number),
                'nf_order_number' => $order->order_number,
                'source'          => $this->sourceFromOrderNumber($order->order_number),
                // Status Hub: mask any status the owner has aliased or held back until
                // dispatch. A historical order is treated as dispatched — it is finished
                // business and never carries a live ETA stamp.
                'status'          => app(\App\Services\CRM\OrderStatusRuleService::class)
                                        ->customerFacingStatus(
                                            $order->order_number,
                                            $order->order_status,
                                            $isHistory || !empty($order->eta_calculated_at)
                                        ),
                'eta_window'      => $isHistory ? null : $this->etaWindowFor($order),
                'placed_at'       => $order->order_date
                    ? \Carbon\Carbon::parse($order->order_date)->toIso8601String() : null,
                'currency'        => $order->currency ?: 'PKR',
                'payment_method'  => $order->payment_method,
                'totals'          => [
                    'subtotal' => (float) $order->subtotal_price,
                    'discount' => (float) $order->discount_total,
                    'shipping' => (float) $order->shipping_total,
                    'tax'      => (float) $order->total_tax,
                    'tip'      => (float) $order->tip_amount,
                    'total'    => (float) $order->total_price,
                ],
                'address'         => [
                    'name'        => $order->name,
                    'phone'       => $order->address_phone,
                    'line1'       => $order->address_line1,
                    'line2'       => $order->address_line2,
                    'city'        => $order->address_city,
                    'province'    => $order->address_province,
                    'postal_code' => $order->address_postal_code,
                    'country'     => $order->address_country,
                ],
                'items'           => $items,
            ];

            return response()->json(['success' => true, 'order' => $payload], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::orderSnapshot failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'order' => null], 500);
        }
    }

    /**
     * Order history keyed on the customer's mobile number (Phase 3).
     *
     * GET /api/customer-app/customers/{mobile}/orders?limit=20
     *
     * {mobile} may be in any format; NF normalizes it exactly the way it tags
     * customers (strip non-digits -> last 10 digits) and returns every
     * production order linked to that customer — SH-, NF- and QUR — newest
     * first. Compact rows; tap-through detail comes from orderSnapshot().
     *
     * The caller MUST pass only the authenticated user's own number — NF
     * authenticates the backend, not the end customer.
     */
    public function customerOrders(Request $request, string $mobile)
    {
        try {
            $normalized = CustomerModel::normalizePhone(urldecode($mobile))['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json([
                    'success'       => true,
                    'matched_phone' => '',
                    'orders'        => [],
                ], 200);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            if (!$customerId) {
                return response()->json([
                    'success'       => true,
                    'matched_phone' => $normalized,
                    'orders'        => [],
                ], 200);
            }

            $limit = (int) $request->query('limit', 20);
            $limit = max(1, min($limit, 50));

            // Combine live (production) + historical (CSV-imported) orders so the
            // customer sees their full history. We UNION the two base tables
            // directly rather than the v_crm_all_orders view: that view carries a
            // prod-only MySQL DEFINER that isn't portable (it errors on a fresh
            // local DB), and reading the tables keeps this customer-facing
            // endpoint decoupled from the analytics view. `id` is NOT unique
            // across the two tables, so every per-order lookup below is keyed on
            // (source_type, id), never id alone.
            // eta_calculated_at rides along for the hold-until-dispatch rule. The
            // history table has no such column, so it is selected as a literal NULL —
            // the column COUNT and ORDER must match on both sides of a UNION.
            $cols  = ['id', 'order_number', 'order_status', 'order_date', 'total_price', 'currency'];
            $prodQ = DB::table('t_crm_prod_order')
                ->where('customer_id', $customerId)
                ->select(array_merge([DB::raw("'production' as source_type")], $cols, ['eta_calculated_at']));
            $histQ = DB::table('t_crm_history_order')
                ->where('customer_id', $customerId)
                ->select(array_merge([DB::raw("'history' as source_type")], $cols, [DB::raw('NULL as eta_calculated_at')]));
            $orders = $prodQ->unionAll($histQ)
                ->orderByDesc('order_date')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();

            // Item count + the distinct SKUs per order, fetched per source from
            // the matching line-item table in ONE grouped query each (no extra
            // round-trips — the SKU concat rides on the same query that already
            // produced the counts). Keyed by "source_type:id" to avoid the
            // prod/history id clash. SKUs let the app show product thumbnails and
            // build a customer's "usual products" set without firing the detail
            // endpoint per order. Capped generously so a normal order carries its
            // FULL SKU list, while a rare bulk order (dozens of distinct SKUs)
            // can't bloat the row or lean on group_concat_max_len.
            $counts = [];
            $skus   = [];
            $skuCap = 25;

            $collectLineMeta = function ($table, $ids, $sourceType) use (&$counts, &$skus, $skuCap) {
                if (empty($ids)) {
                    return;
                }
                DB::table($table)
                    ->whereIn('order_id', $ids)
                    ->groupBy('order_id')
                    ->selectRaw("order_id, COUNT(*) as c, GROUP_CONCAT(DISTINCT NULLIF(sku, '')) as skus")
                    ->get()
                    ->each(function ($r) use (&$counts, &$skus, $sourceType, $skuCap) {
                        $key          = $sourceType . ':' . $r->order_id;
                        $counts[$key] = (int) $r->c;
                        $list         = array_values(array_filter(array_map('trim', explode(',', (string) $r->skus))));
                        $skus[$key]   = array_slice($list, 0, $skuCap);
                    });
            };

            $collectLineMeta('t_crm_prod_order_line_item', $orders->where('source_type', 'production')->pluck('id')->all(), 'production');
            $collectLineMeta('t_crm_history_order_line_item', $orders->where('source_type', 'history')->pluck('id')->all(), 'history');

            // Status Hub: mask any status the owner has aliased or held back until
            // dispatch. Historical rows are treated as dispatched (finished business).
            $statusRules = app(\App\Services\CRM\OrderStatusRuleService::class);

            $rows = $orders->map(fn ($o) => [
                'order_number'    => $this->stripShopifyPrefix($o->order_number),
                'nf_order_number' => $o->order_number,
                'source'          => $this->sourceFromOrderNumber($o->order_number),
                'status'          => $statusRules->customerFacingStatus(
                    $o->order_number,
                    $o->order_status,
                    $o->source_type === 'history' || !empty($o->eta_calculated_at)
                ),
                'placed_at'       => $o->order_date
                    ? \Carbon\Carbon::parse($o->order_date)->toIso8601String() : null,
                'total'           => (float) $o->total_price,
                'currency'        => $o->currency ?: 'PKR',
                'item_count'      => (int) ($counts[$o->source_type . ':' . $o->id] ?? 0),
                'skus'            => $skus[$o->source_type . ':' . $o->id] ?? [],
            ])->values();

            return response()->json([
                'success'       => true,
                'matched_phone' => $normalized,
                'orders'        => $rows,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::customerOrders failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success'       => false,
                'matched_phone' => '',
                'orders'        => [],
            ], 500);
        }
    }

    /**
     * Customer existence + profile + verified pin (Phase 4).
     *
     * GET /api/customer-app/customers/{mobile}
     *
     * Used by the customer app at login/register to decide "do we know this
     * number?". Returns exists=false (200, not 404) for an unknown number so
     * the app can route to registration. The bare {mobile} path hits this;
     * {mobile}/orders is the separate history endpoint.
     */
    public function customerProfile(Request $request, string $mobile)
    {
        try {
            $normalized = CustomerModel::normalizePhone(urldecode($mobile))['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json([
                    'success' => true, 'matched_phone' => '', 'exists' => false, 'customer' => null,
                ], 200);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            if (!$customerId) {
                return response()->json([
                    'success' => true, 'matched_phone' => $normalized, 'exists' => false, 'customer' => null,
                ], 200);
            }

            $c = DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->select([
                    'id', 'first_name', 'last_name', 'email', 'phone_original',
                    'total_orders', 'total_spent', 'first_order_date', 'last_order_date',
                    'is_active', 'created_at',
                    'address1', 'address2', 'city', 'province', 'postal_code', 'country',
                    'address_saved_by', 'address_saved_at', 'profile_saved_by', 'profile_saved_at',
                    'latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude',
                    'verified_location_url', 'verified_location_saved_by', 'verified_location_saved_at',
                ])
                ->first();

            if (!$c) {
                return response()->json([
                    'success' => true, 'matched_phone' => $normalized, 'exists' => false, 'customer' => null,
                ], 200);
            }

            return response()->json([
                'success'       => true,
                'matched_phone' => $normalized,
                'exists'        => true,
                'customer'      => [
                    'name'             => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: null,
                    'first_name'       => $c->first_name,
                    'last_name'        => $c->last_name,
                    'email'            => $c->email,
                    'phone'            => $c->phone_original,
                    'total_orders'     => (int) $c->total_orders,
                    'total_spent'      => (float) $c->total_spent,
                    'is_active'        => (bool) $c->is_active,
                    'customer_since'   => $c->created_at
                        ? \Carbon\Carbon::parse($c->created_at)->toIso8601String() : null,
                    'first_order_date' => $c->first_order_date
                        ? \Carbon\Carbon::parse($c->first_order_date)->toIso8601String() : null,
                    'last_order_date'  => $c->last_order_date
                        ? \Carbon\Carbon::parse($c->last_order_date)->toIso8601String() : null,
                    'address'          => [
                        'line1'             => $c->address1,
                        'line2'             => $c->address2,
                        'city'              => $c->city,
                        'province'          => $c->province,
                        'postal_code'       => $c->postal_code,
                        'country'           => $c->country,
                        'saved_by'          => $this->attributionLabel($c->address_saved_by),
                        'saved_by_customer' => ((int) $c->address_saved_by) === self::CUSTOMER_PIN_SAVED_BY,
                        'saved_at'          => $c->address_saved_at
                            ? \Carbon\Carbon::parse($c->address_saved_at)->toIso8601String() : null,
                    ],
                    // Attribution for name/email edits (parallel to address.saved_by).
                    'profile_saved_by'          => $this->attributionLabel($c->profile_saved_by),
                    'profile_saved_by_customer' => ((int) $c->profile_saved_by) === self::CUSTOMER_PIN_SAVED_BY,
                    'profile_saved_at'          => $c->profile_saved_at
                        ? \Carbon\Carbon::parse($c->profile_saved_at)->toIso8601String() : null,
                    'verified_pin'     => $this->buildPin($c),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::customerProfile failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false, 'matched_phone' => '', 'exists' => false, 'customer' => null,
            ], 500);
        }
    }

    /**
     * Customer writes/updates their own verified pin (Phase 4).
     *
     * POST /api/customer-app/customers/{mobile}/location
     * body: { latitude, longitude, url?, first_name?, last_name? }
     *
     * If the number is brand-new (no NF customer yet) a minimal customer row
     * is created so the pin has a home — this is the "new customer registers
     * and drops a pin" case. Attribution is stamped with the customer sentinel
     * (-9999), which reads back as "saved_by": "Customer". The pin overwrites
     * any existing one (per current business decision).
     */
    public function saveCustomerLocation(Request $request, string $mobile)
    {
        try {
            $validated = $request->validate([
                'latitude'   => 'required|numeric|between:-90,90',
                'longitude'  => 'required|numeric|between:-180,180',
                'url'        => 'nullable|string|max:500',
                'first_name' => 'nullable|string|max:100',
                'last_name'  => 'nullable|string|max:100',
            ]);

            $norm       = CustomerModel::normalizePhone(urldecode($mobile));
            $normalized = $norm['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json(['success' => false, 'message' => 'Invalid mobile number.'], 422);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            $created    = false;

            if (!$customerId) {
                $customer = CustomerModel::create([
                    'phone'            => $normalized,
                    'phone_normalized' => $normalized,
                    'phone_original'   => $norm['original'] ?? $normalized,
                    'first_name'       => $validated['first_name'] ?? null,
                    'last_name'        => $validated['last_name'] ?? null,
                    'country'          => 'Pakistan',
                    'is_active'        => 1,
                    'created_by'       => self::CUSTOMER_PIN_SAVED_BY,
                ]);
                $customerId = $customer->id;
                $created    = true;
            }

            $update = [
                'latitude'                   => $validated['latitude'],
                'longitude'                  => $validated['longitude'],
                'verified_location_saved_by' => self::CUSTOMER_PIN_SAVED_BY,
                'verified_location_saved_at' => now(),
                'updated_by'                 => self::CUSTOMER_PIN_SAVED_BY,
                // A coordinate pin is canonical; drop any stale URL unless one is supplied.
                'verified_location_url'      => !empty($validated['url']) ? $validated['url'] : null,
            ];

            // Snapshot the pre-write pin so the change is auditable (the old
            // coordinates are otherwise silently overwritten). null for a
            // just-created customer → logged as "first pin set". The unlock-grant
            // columns ride along so the grace window below can honour a longer
            // grant that is already live.
            $existingPin = DB::table('t_crm_prod_customer')->where('id', $customerId)
                ->first([
                    'first_name', 'last_name', 'latitude', 'longitude',
                    'verified_pin_unlocked_until', 'verified_pin_unlocked_by',
                ]);

            // A customer's own pin opens the same 24h grace window a staff save
            // does (owner ruling, Aug-2026): the rider standing at the door is
            // the ground truth for a pin the customer dropped from their sofa,
            // so he may correct it once without phoning the office. His save then
            // consumes the grant and the pin re-locks.
            $update = array_merge($update, CustomerModel::pinGrantGrace(
                $existingPin->verified_pin_unlocked_until ?? null,
                $existingPin->verified_pin_unlocked_by ?? null,
                self::CUSTOMER_PIN_SAVED_BY
            ));

            DB::table('t_crm_prod_customer')->where('id', $customerId)->update($update);

            \App\Services\AuditLogger::logCustomerPinChange(
                $customerId,
                $existingPin ? trim(($existingPin->first_name ?? '') . ' ' . ($existingPin->last_name ?? '')) : null,
                $existingPin->latitude ?? null,
                $existingPin->longitude ?? null,
                $validated['latitude'],
                $validated['longitude'],
                'Verified pin (customer app)'
            );

            // Pin changed → re-derive the region from it (see refreshAfterPinSave).
            \App\Services\RegionDetectionService::refreshAfterPinSave($customerId);

            $c = DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->select([
                    'latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude',
                    'verified_location_url', 'verified_location_saved_by', 'verified_location_saved_at',
                ])
                ->first();

            return response()->json([
                'success'          => true,
                'matched_phone'    => $normalized,
                'created_customer' => $created,
                'verified_pin'     => $this->buildPin($c),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::saveCustomerLocation failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to save location.'], 500);
        }
    }

    /**
     * Customer writes/updates their postal address (Phase 5 — write-back).
     *
     * POST /api/customer-app/customers/{mobile}/address
     * body: { line1*, city*, line2?, province?, postal_code?, country? }
     *
     * Mirrors saveCustomerLocation: creates a minimal customer row if the
     * number is brand-new, overwrites the CRM address (customer-edit-wins per
     * business decision), and stamps attribution with the customer sentinel so
     * it reads back as "saved_by": "Customer" (vs an ops user's name).
     *
     * NOTE: this writes only the postal-address TEXT. It does NOT touch the
     * delivery pin (latitude/longitude / verified_location_*) — the rider drop
     * point stays whatever the pin says, so editing the address never silently
     * moves a delivery.
     */
    public function saveCustomerAddress(Request $request, string $mobile)
    {
        try {
            $validated = $request->validate([
                'line1'       => 'required|string|max:255',
                'city'        => 'required|string|max:255',
                'line2'       => 'nullable|string|max:255',
                'province'    => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:50',
                'country'     => 'nullable|string|max:100',
            ]);

            $norm       = CustomerModel::normalizePhone(urldecode($mobile));
            $normalized = $norm['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json(['success' => false, 'message' => 'Invalid mobile number.'], 422);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            $created    = false;

            if (!$customerId) {
                $customer = CustomerModel::create([
                    'phone'            => $normalized,
                    'phone_normalized' => $normalized,
                    'phone_original'   => $norm['original'] ?? $normalized,
                    'country'          => 'Pakistan',
                    'is_active'        => 1,
                    'created_by'       => self::CUSTOMER_PIN_SAVED_BY,
                ]);
                $customerId = $customer->id;
                $created    = true;
            }

            $update = [
                'address1'         => $validated['line1'],
                'address2'         => $validated['line2'] ?? null,
                'city'             => $validated['city'],
                'province'         => $validated['province'] ?? null,
                'postal_code'      => $validated['postal_code'] ?? null,
                'country'          => $validated['country'] ?? 'Pakistan',
                'address_saved_by' => self::CUSTOMER_PIN_SAVED_BY,
                'address_saved_at' => now(),
                'updated_by'       => self::CUSTOMER_PIN_SAVED_BY,
            ];

            DB::table('t_crm_prod_customer')->where('id', $customerId)->update($update);

            $c = DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->select([
                    'address1', 'address2', 'city', 'province', 'postal_code', 'country',
                    'address_saved_by', 'address_saved_at',
                ])
                ->first();

            return response()->json([
                'success'          => true,
                'matched_phone'    => $normalized,
                'created_customer' => $created,
                'address'          => [
                    'line1'             => $c->address1,
                    'line2'             => $c->address2,
                    'city'              => $c->city,
                    'province'          => $c->province,
                    'postal_code'       => $c->postal_code,
                    'country'           => $c->country,
                    'saved_by'          => $this->attributionLabel($c->address_saved_by),
                    'saved_by_customer' => ((int) $c->address_saved_by) === self::CUSTOMER_PIN_SAVED_BY,
                    'saved_at'          => $c->address_saved_at
                        ? \Carbon\Carbon::parse($c->address_saved_at)->toIso8601String() : null,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::saveCustomerAddress failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to save address.'], 500);
        }
    }

    /**
     * Customer writes/updates their name / email (Phase 5 — write-back).
     *
     * POST /api/customer-app/customers/{mobile}/profile
     * body: { first_name?, last_name?, email? }  (any subset)
     *
     * - Only fields actually present in the request are written; the rest are
     *   left untouched (so a name edit never wipes the email and vice-versa).
     * - The mobile number is the immutable identity key and is never written.
     * - email is NOT unique in t_crm_prod_customer, so a clash can't hard-fail;
     *   instead, if the email already belongs to ANOTHER customer we skip
     *   writing it and return email_taken:true (soft), per the dev's request.
     * - Same create-if-new + customer-set attribution as the address write.
     */
    public function saveCustomerProfile(Request $request, string $mobile)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'nullable|string|max:100',
                'last_name'  => 'nullable|string|max:100',
                'email'      => 'nullable|email|max:255',
            ]);

            $hasFirst = $request->has('first_name');
            $hasLast  = $request->has('last_name');
            $hasEmail = $request->has('email');

            if (!$hasFirst && !$hasLast && !$hasEmail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nothing to update — send at least one of first_name, last_name, email.',
                ], 422);
            }

            $norm       = CustomerModel::normalizePhone(urldecode($mobile));
            $normalized = $norm['normalized'] ?? '';

            if ($normalized === '' || $normalized === str_repeat('0', 10)) {
                return response()->json(['success' => false, 'message' => 'Invalid mobile number.'], 422);
            }

            $customerId = $this->resolveCustomerIdByPhone($normalized);
            $created    = false;

            if (!$customerId) {
                // Create with name only; email is handled below (collision-checked).
                $customer = CustomerModel::create([
                    'phone'            => $normalized,
                    'phone_normalized' => $normalized,
                    'phone_original'   => $norm['original'] ?? $normalized,
                    'first_name'       => $hasFirst ? ($validated['first_name'] ?? null) : null,
                    'last_name'        => $hasLast ? ($validated['last_name'] ?? null) : null,
                    'country'          => 'Pakistan',
                    'is_active'        => 1,
                    'created_by'       => self::CUSTOMER_PIN_SAVED_BY,
                ]);
                $customerId = $customer->id;
                $created    = true;
            }

            $update     = [];
            $emailTaken = false;

            if ($hasFirst) {
                $update['first_name'] = $validated['first_name'] ?? null;
            }
            if ($hasLast) {
                $update['last_name'] = $validated['last_name'] ?? null;
            }
            if ($hasEmail) {
                $email = $validated['email'] ?? null;
                if (!empty($email)) {
                    $clash = DB::table('t_crm_prod_customer')
                        ->where('email', $email)
                        ->where('id', '<>', $customerId)
                        ->exists();
                    if ($clash) {
                        $emailTaken = true; // skip writing — soft flag back to the app
                    } else {
                        $update['email'] = $email;
                    }
                } else {
                    $update['email'] = null; // explicit clear
                }
            }

            if (!empty($update)) {
                $update['profile_saved_by'] = self::CUSTOMER_PIN_SAVED_BY;
                $update['profile_saved_at'] = now();
                $update['updated_by']       = self::CUSTOMER_PIN_SAVED_BY;
                DB::table('t_crm_prod_customer')->where('id', $customerId)->update($update);
            }

            $c = DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->select(['first_name', 'last_name', 'email', 'profile_saved_by', 'profile_saved_at'])
                ->first();

            $response = [
                'success'          => true,
                'matched_phone'    => $normalized,
                'created_customer' => $created,
                'customer'         => [
                    'first_name'        => $c->first_name,
                    'last_name'         => $c->last_name,
                    'email'             => $c->email,
                    'saved_by'          => $this->attributionLabel($c->profile_saved_by),
                    'saved_by_customer' => ((int) $c->profile_saved_by) === self::CUSTOMER_PIN_SAVED_BY,
                    'saved_at'          => $c->profile_saved_at
                        ? \Carbon\Carbon::parse($c->profile_saved_at)->toIso8601String() : null,
                ],
            ];
            if ($emailTaken) {
                $response['email_taken'] = true;
            }

            return response()->json($response, 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::saveCustomerProfile failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to save profile.'], 500);
        }
    }

    /**
     * Customer changes the payment method on their own order (Phase 5).
     *
     * POST /api/customer-app/orders/{orderNumber}/payment-method
     * body: { payment_method: "cash" | "online" }
     *
     * Reuses the exact same logic riders/managers use (normalize old value → map
     * new value → OrderModel::save()), so it goes through the shared
     * OrderPaymentChangeObserver (the gated WhatsApp "bank details when switching
     * to online" automation). Changing the method BEFORE delivery has no ledger
     * effect — cash-vs-online is settled at delivery.
     *
     * Guard: customers may only change on a still-open order. Delivered /
     * completed / cancelled / refunded orders are rejected (a delivered order's
     * money is already reconciled; a cancelled one is finished).
     */
    public function changePaymentMethod(Request $request, string $orderNumber)
    {
        try {
            $validated = $request->validate([
                'payment_method' => 'required|string|in:cash,online',
            ]);

            $nfOrderNumber = $this->resolveAnyOrderNumber($orderNumber);

            // Live orders only (t_crm_prod_order). Historical orders are past and
            // would be blocked by the status guard anyway.
            $order = \App\Models\CRM\OrderModel::where('order_number', $nfOrderNumber)->first();
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
            }

            $blocked = ['delivered', 'completed', 'cancelled', 'refunded'];
            if (in_array($order->order_status, $blocked, true)) {
                return response()->json([
                    'success'      => false,
                    'message'      => 'Payment method can no longer be changed for this order.',
                    // Status Hub: mask aliased/held statuses (the $blocked gate stays on RAW status).
                    'order_status' => app(\App\Services\CRM\OrderStatusRuleService::class)
                                        ->customerFacingStatus(
                                            $order->order_number,
                                            $order->order_status,
                                            !empty($order->eta_calculated_at)
                                        ),
                ], 422);
            }

            $newType = $validated['payment_method']; // 'cash' | 'online'

            // Treat NULL/empty/cod as cash (same normalization as the rider path).
            $normalizedOld = strtolower(trim($order->payment_method ?? ''));
            $oldType = (empty($normalizedOld) || in_array($normalizedOld, ['cash', 'cash_on_delivery', 'cod']))
                ? 'cash'
                : 'online';

            if ($oldType === $newType) {
                return response()->json([
                    'success'        => true,
                    'unchanged'      => true,
                    'matched_order'  => $order->order_number,
                    'payment_method' => $newType,
                    'message'        => 'Payment method is already set to ' . $newType . '.',
                ], 200);
            }

            // Map to the same stored values the rider path writes.
            $order->payment_method = $newType === 'cash' ? 'cash_on_delivery' : 'online_payment';
            $order->save(); // fires OrderPaymentChangeObserver (gated WA automation)

            Log::info('Payment method changed via customer app', [
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'old_method'   => $normalizedOld,
                'new_method'   => $order->payment_method,
                'order_status' => $order->order_status,
                'source'       => 'customer_app',
            ]);

            return response()->json([
                'success'        => true,
                'matched_order'  => $order->order_number,
                'payment_method' => $newType,
                'message'        => 'Payment method updated to ' . ($newType === 'cash' ? 'Cash' : 'Online/Bank') . '.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false, 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::changePaymentMethod failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to change payment method.'], 500);
        }
    }

    /**
     * Invoice image for an order (Phase 4).
     *
     * GET /api/customer-app/orders/{orderNumber}/invoice
     *
     * Returns a URL to the rendered invoice PNG (the same image NF sends over
     * WhatsApp), or available=false if it hasn't been generated on the web
     * side yet (an operator must preview/send it once to capture it). The
     * customer-app developer handles the display optics ("Invoice ready" etc).
     */
    public function orderInvoice(Request $request, string $orderNumber)
    {
        try {
            $nfOrderNumber = $this->resolveAnyOrderNumber($orderNumber);

            $order = DB::table('t_crm_prod_order')
                ->where('order_number', $nfOrderNumber)
                ->select(['id', 'order_number'])
                ->first();

            if (!$order) {
                return response()->json(['success' => false, 'available' => false, 'image_url' => null], 404);
            }

            $result = app(\App\Http\Controllers\Web\WhatsAppWebController::class)
                ->readInvoiceImageUrlForSend($order->id);

            $available = !empty($result['success']) && !empty($result['image_url']);

            return response()->json([
                'success'         => true,
                'available'       => $available,
                'order_number'    => $this->stripShopifyPrefix($order->order_number),
                'nf_order_number' => $order->order_number,
                'image_url'       => $available ? $result['image_url'] : null,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('CustomerAppController::orderInvoice failed', [
                'order_number' => $orderNumber,
                'error'        => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'available' => false, 'image_url' => null], 500);
        }
    }

    /**
     * Build the verified-pin block for a customer row. Prefers explicit
     * verified coords, falls back to geocoded. Returns null when we have
     * neither coords nor a URL. Translates the customer sentinel to a
     * "Customer" label.
     */
    private function buildPin(object $c): ?array
    {
        $explicitLat = $c->latitude;
        $explicitLng = $c->longitude;
        $hasExplicit = !($explicitLat === null || $explicitLat === '' || $explicitLng === null || $explicitLng === '');

        $lat = $hasExplicit ? $explicitLat : ($c->geocoded_latitude ?? null);
        $lng = $hasExplicit ? $explicitLng : ($c->geocoded_longitude ?? null);
        $hasCoords = !($lat === null || $lng === null || $lat === '' || $lng === '');

        if (!$hasCoords && empty($c->verified_location_url)) {
            return null;
        }

        $savedById      = (int) ($c->verified_location_saved_by ?? 0);
        $savedByCustomer = $savedById === self::CUSTOMER_PIN_SAVED_BY;

        if ($savedByCustomer) {
            $savedBy = 'Customer';
        } elseif ($savedById > 0) {
            $savedBy = DB::table('t_sys_user')->where('id', $savedById)->value('fullname');
        } else {
            $savedBy = null;
        }

        return [
            'lat'               => $hasCoords ? (float) $lat : null,
            'lng'               => $hasCoords ? (float) $lng : null,
            'is_verified'       => $hasExplicit || !empty($c->verified_location_url),
            'google_maps_url'   => $c->verified_location_url
                ?: ($hasCoords ? "https://www.google.com/maps?q={$lat},{$lng}" : null),
            'saved_by'          => $savedBy,
            'saved_by_customer' => $savedByCustomer,
            'saved_at'          => $c->verified_location_saved_at
                ? \Carbon\Carbon::parse($c->verified_location_saved_at)->toIso8601String() : null,
        ];
    }

    /**
     * Translate a *_saved_by id into a human label, the same way buildPin does:
     * the customer sentinel reads as "Customer", a real ops user id resolves to
     * their name, anything else is null. Used for address/profile attribution.
     */
    private function attributionLabel($savedById): ?string
    {
        $id = (int) ($savedById ?? 0);
        if ($id === self::CUSTOMER_PIN_SAVED_BY) {
            return 'Customer';
        }
        if ($id > 0) {
            return DB::table('t_sys_user')->where('id', $id)->value('fullname');
        }
        return null;
    }

    /**
     * Resolve the primary customer id for a normalized phone, following the
     * merged-customer chain so merged duplicates still return the surviving
     * customer's full history. Returns null when no customer matches.
     */
    private function resolveCustomerIdByPhone(string $normalizedPhone): ?int
    {
        $customer = CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->value('id');

        if ($customer) {
            return (int) $customer;
        }

        // Only merged rows exist — follow the chain to the survivor.
        $merged = CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNotNull('merged_into_customer_id')
            ->value('merged_into_customer_id');

        $guard = 0;
        while ($merged && $guard++ < 10) {
            $next = CustomerModel::where('id', $merged)->value('merged_into_customer_id');
            if (!$next) {
                return (int) $merged;
            }
            $merged = $next;
        }

        return $merged ? (int) $merged : null;
    }

    /** Map an NF order_number prefix to a coarse source label. */
    private function sourceFromOrderNumber(?string $nf): string
    {
        $nf = (string) $nf;
        if (str_starts_with($nf, 'SH-'))  return 'shopify';
        if (str_starts_with($nf, 'NF-'))  return 'manual';
        if (str_starts_with($nf, 'QUR'))  return 'qurbani';
        return 'other';
    }

    /** Strip only the SH- prefix (Shopify's bare number); leave NF-/QUR as-is. */
    private function stripShopifyPrefix(string $orderNumber): string
    {
        return str_starts_with($orderNumber, 'SH-') ? substr($orderNumber, 3) : $orderNumber;
    }

    /**
     * Resolve a caller-supplied order number to the stored NF order_number.
     * A value with letters is treated as a full NF number (prefix upper-cased
     * for a stable match); a bare numeric value is assumed to be a Shopify
     * order and gets the SH- prefix.
     */
    private function resolveAnyOrderNumber(string $raw): string
    {
        $value = ltrim(trim(urldecode($raw)), '#');
        if ($value === '') {
            return $value;
        }
        // ✂ Oct-2026: a SPLIT Shopify invoice reaches the app as "23614-A" (the
        // payload strips SH- exactly as for the original). Its only letter is the
        // split suffix, so without this it fell into the "full NF number" branch,
        // looked up order_number '23614-A' and 404'd on tracking/invoice.
        if (preg_match('/^\d+-[A-Za-z]$/', $value)) {
            return 'SH-' . strtoupper($value);
        }
        if (preg_match('/[A-Za-z]/', $value)) {
            return strtoupper($value);
        }
        return 'SH-' . $value;
    }

    /**
     * Coarse human ETA window for a snapshot — only when out for delivery and
     * an ETA exists. Mirrors CustomerAppWebhookEmitter's format so the snapshot
     * and the webhook agree.
     */
    private function etaWindowFor(object $order): ?string
    {
        if (strtolower((string) $order->order_status) !== 'out_for_delivery') {
            return null;
        }
        if (empty($order->estimated_delivery_at)) {
            return null;
        }
        try {
            $band = (int) config('customer_app.eta_window_band_minutes', 30);
            $band = $band > 0 ? $band : 30;

            $round = (int) config('customer_app.eta_window_round_to_minutes', 10);
            $round = $round > 0 ? $round : 10;

            $eta           = \Carbon\Carbon::parse($order->estimated_delivery_at)->copy()->second(0);
            $flooredMinute = intdiv($eta->minute, $round) * $round;
            $start         = $eta->copy()->minute($flooredMinute)->second(0);
            $end           = $start->copy()->addMinutes($band);

            $day = $start->isToday() ? 'Today, '
                 : ($start->isTomorrow() ? 'Tomorrow, ' : $start->format('M j') . ', ');

            $window = $start->format('A') === $end->format('A')
                ? $start->format('g:i') . '-' . $end->format('g:i') . ' ' . $end->format('A')
                : $start->format('g:i A') . '-' . $end->format('g:i A');

            return $day . $window;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Number of the rider's other out-for-delivery stops sequenced ahead of
     * this one. 0 means this drop is next.
     */
    private function stopsAway(object $order): int
    {
        if ($order->delivery_priority === null) {
            return 0;
        }

        return (int) DB::table('t_crm_prod_order')
            ->where('assigned_rider_user_id', $order->assigned_rider_user_id)
            ->where('order_status', 'out_for_delivery')
            ->whereNotNull('delivery_priority')
            ->where('delivery_priority', '<', $order->delivery_priority)
            ->count();
    }

    /**
     * Resolve the customer drop point: verified lat/lng first, geocoded
     * fallback. Returns null when we have no usable coordinates.
     */
    private function resolveDestination($customerId): ?array
    {
        if (empty($customerId)) {
            return null;
        }

        $customer = DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->select(['latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude'])
            ->first();

        if (!$customer) {
            return null;
        }

        $lat = $customer->latitude ?: $customer->geocoded_latitude;
        $lng = $customer->longitude ?: $customer->geocoded_longitude;

        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /** Strip the NF prefix for the customer-facing order number. */
    private function stripPrefix(string $orderNumber): string
    {
        $prefix = (string) config('customer_app.strip_prefix_for_payload', 'SH-');
        if ($prefix !== '' && str_starts_with($orderNumber, $prefix)) {
            return substr($orderNumber, strlen($prefix));
        }
        return $orderNumber;
    }
}
