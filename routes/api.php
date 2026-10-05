<?php


use App\Http\Controllers\AuthController; 
use App\Http\Controllers\MailController; 
use App\Http\Controllers\Webhook\ShopifyController;
use App\Http\Controllers\Webhook\WooController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Controllers\API\WhatsAppController;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;


// Public routes (requires no authentication)

Route::group([
    'prefix' => 'public'
], function ($router) {
    Route::get('/sendbasicemail', [MailController::class, 'sendEmail']);
    Route::get('storage-link', function () {
        Artisan::call('storage:link'); // command
        dd("Done!!!");
    });
    Route::get('/xclean', function () {
        $exitCode0 = Artisan::call('config:clear');
        $exitCode1 = Artisan::call('cache:clear');
        $exitCode2 = Artisan::call('view:clear');
        $exitCode3 = Artisan::call('route:clear');
        $exitCode4 = Artisan::call('config:cache');
        // Also reset the PHP opcode cache so freshly-uploaded PHP (new routes/
        // controllers) is actually loaded on shared hosting that caches bytecode.
        $opcache = function_exists('opcache_reset') ? (@opcache_reset() ? 'reset' : 'reset-failed') : 'unavailable';
        dd('CACHE-CLEARED, VIEW-CLEARED, ROUTE-CLEARED & CONFIG-CACHED WAS SUCCESSFUL! OPCACHE: ' . $opcache);
    });
});

// Webhook Routes
Route::prefix('webhook')->group(function () {

    // Shopify Routes
    Route::prefix('shopify')->controller(ShopifyController::class)->group(function () {
        Route::prefix('order')->group(function () {
            Route::get('get/{id}', 'get');
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::delete('remove/{id}', 'remove');
        });
    });

    // WooCommerce Routes
    Route::prefix('woo')->controller(WooController::class)->group(function () {
        Route::prefix('order')->group(function () {
            Route::get('get/{id}', 'get');
            Route::post('list', 'list');
            Route::post('store', 'store');
            Route::post('test', 'test'); // Test endpoint for debugging webhooks
            Route::delete('remove/{id}', 'remove');
        });
    });

    // WhatsApp Business API Webhook (public - called by Meta)
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive']);
});

// Customer App — server-to-server pull endpoints (Phase 2).
// Authenticated by the `customer.app` middleware (bearer token), NOT by
// Sanctum. Called by the customer-app (Vercel) backend, which resolves the
// customer from its own JWT and enforces order ownership before proxying.
//
// Jul-2026 additions:
//  - LogCustomerAppRequest (FIRST, so 401/429 rejections are recorded too):
//    one structured line per request into the customer_app daily log channel;
//    the "Customer App Stats" admin page (/customer-app-stats) aggregates it.
//    Measure first — then size the real per-endpoint limits from data.
//  - throttle:600,1 — a deliberately GENEROUS safety backstop (600 req/min per
//    caller IP), far above anything 50–100 real users produce. Its only job is
//    to stop a runaway retry loop / leaked token from consuming the shared
//    host's PHP workers (which staff tools share) or mass-inserting junk
//    customers via the create-if-new POST endpoints. Real users never hit it.
Route::middleware([
    \App\Http\Middleware\LogCustomerAppRequest::class,
    'customer.app',
    'throttle:600,1',
])->prefix('customer-app')->group(function () {
    Route::get('/orders/{orderNumber}/tracking', [\App\Http\Controllers\API\CustomerAppController::class, 'tracking'])
        ->where('orderNumber', '.*'); // allow SH-1234, 1234, or encoded #1234

    // Phase 4 — invoice image for an order (returns a URL to the rendered PNG,
    // or available:false if it hasn't been generated on the web side yet).
    Route::get('/orders/{orderNumber}/invoice', [\App\Http\Controllers\API\CustomerAppController::class, 'orderInvoice'])
        ->where('orderNumber', '[^/]+');

    // Phase 3 — full order snapshot. Accepts a bare Shopify number (1234 ->
    // assumed SH-) or any full NF number (SH-1234, NF-2001, QUR26-045).
    Route::get('/orders/{orderNumber}', [\App\Http\Controllers\API\CustomerAppController::class, 'orderSnapshot'])
        ->where('orderNumber', '[^/]+');

    // Phase 5 — customer changes their order's payment method (cash <-> online).
    // Reuses the shared rider/manager change logic; only allowed while the order
    // is still open (not delivered/completed/cancelled/refunded).
    Route::post('/orders/{orderNumber}/payment-method', [\App\Http\Controllers\API\CustomerAppController::class, 'changePaymentMethod'])
        ->where('orderNumber', '[^/]+');

    // Phase 3 — order history keyed on the customer's mobile number (any
    // format; NF normalizes to the last 10 digits before matching).
    Route::get('/customers/{mobile}/orders', [\App\Http\Controllers\API\CustomerAppController::class, 'customerOrders'])
        ->where('mobile', '[^/]+');

    // Phase 4 — customer existence + profile + verified pin (login/register
    // check). Bare {mobile} (no /orders suffix) hits this.
    Route::get('/customers/{mobile}', [\App\Http\Controllers\API\CustomerAppController::class, 'customerProfile'])
        ->where('mobile', '[^/]+');

    // Phase 4 — customer writes/updates their own verified pin. Creates a
    // minimal customer row if the number is brand-new. Attribution is stamped
    // with the customer sentinel id (-9999).
    Route::post('/customers/{mobile}/location', [\App\Http\Controllers\API\CustomerAppController::class, 'saveCustomerLocation'])
        ->where('mobile', '[^/]+');

    // Phase 5 — customer-app write-back. The app pushes profile edits made on
    // its side so NF stays the source of truth. Same auth/token + last-10-digit
    // mobile matching + create-if-new + customer-set attribution as the pin.
    Route::post('/customers/{mobile}/address', [\App\Http\Controllers\API\CustomerAppController::class, 'saveCustomerAddress'])
        ->where('mobile', '[^/]+');
    Route::post('/customers/{mobile}/profile', [\App\Http\Controllers\API\CustomerAppController::class, 'saveCustomerProfile'])
        ->where('mobile', '[^/]+');
});

//Webhook

//Route::get('sendbasicemail', 'MailController@sendEmail');

 
 
 


 

  
 
// Protected routes (requires authentication)

// Mobile App API Routes
// These routes are for the mobile app and return JSON responses

// Authentication (uses existing AuthController)
Route::post('/auth/authenticate', [AuthController::class, 'authenticate']);

// App version check - PUBLIC endpoint (no auth required - anyone can check for updates)
Route::get('/app/version', [\App\Http\Controllers\API\AppController::class, 'getLatestVersion']);
// Qurbani companion APK uses its own endpoint so a new main release doesn't
// accidentally "upgrade" (= force-sideload) the Qurbani app with the main APK.
Route::get('/app/version/qurbani', [\App\Http\Controllers\API\AppController::class, 'getLatestQurbaniVersion']);
// NF Messages APK (com.nizamifarmsmobile.messages) — same reasoning as Qurbani:
// its own tracker so the three sibling APKs never cross-nag each other.
// Also public: api.js's PUBLIC_ENDPOINTS matches on `.includes('/app/version')`,
// so this path is skipped by 401 handling without any mobile change.
Route::get('/app/version/messages', [\App\Http\Controllers\API\AppController::class, 'getLatestMessagesVersion']);

// Authenticated mobile routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth endpoints
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/logout', [AuthController::class, 'logout']);

    Route::prefix('rider')->group(function () {
        // Dashboard
        Route::get('/dashboard', [\App\Http\Controllers\API\RiderController::class, 'dashboard']);

        // 🚚 VAN OPERATIONS (Aug-2026). The driver's journey strip + the scans.
        //    `manifest` is the whole strip in one call so his home screen never
        //    flickers between two round trips. `start-leg` is the ONE action
        //    behind "Where to next?" — there is deliberately no "van left"
        //    endpoint: leaving is implied by the first leg, and the ping fires
        //    itself exactly once.
        Route::get('/van/manifest', [\App\Http\Controllers\API\VanController::class, 'manifest']);
        Route::post('/van/start-leg', [\App\Http\Controllers\API\VanController::class, 'startLeg']);
        Route::post('/van/dispatch-selected', [\App\Http\Controllers\API\VanController::class, 'dispatchSelected']);
        Route::post('/van/orders/{id}/load-scan', [\App\Http\Controllers\API\VanController::class, 'loadScan']);
        Route::post('/van/orders/{id}/handover-scan', [\App\Http\Controllers\API\VanController::class, 'handoverScan']);
        Route::post('/van/orders/{id}/handover-override', [\App\Http\Controllers\API\VanController::class, 'handoverOverride']);
        Route::post('/van/orders/{id}/unload', [\App\Http\Controllers\API\VanController::class, 'unload']);
        // 🆘 Sep-2026: the rider cannot scan the label at the van → tell the store
        //    (push + note on the Van tab) so a manager records a no-scan handover.
        Route::post('/van/orders/{id}/handover-help', [\App\Http\Controllers\API\VanController::class, 'handoverHelp']);

        // 📍 Meet-up STOPS. Presets live in t_ops_company_locations behind
        //    `is_handover_point` (they are NOT offices — see LocationService).
        //    A stop may be a NAME with no pin until a driver first stands there;
        //    an ad-hoc pin is trip-local until a manager promotes it.
        //    Declared before '/van/stops/{id}' so 'promote' is not read as an id.
        Route::get('/van/stops', [\App\Http\Controllers\API\VanController::class, 'stops']);
        Route::post('/van/stops', [\App\Http\Controllers\API\VanController::class, 'saveStop']);
        Route::post('/van/stops/set', [\App\Http\Controllers\API\VanController::class, 'setStop']);
        Route::post('/van/stops/reached', [\App\Http\Controllers\API\VanController::class, 'reachedStop']);
        Route::post('/van/stops/complete', [\App\Http\Controllers\API\VanController::class, 'completeStop']);
        Route::post('/van/stops/promote/{handoverId}', [\App\Http\Controllers\API\VanController::class, 'promoteStop']);
        Route::post('/van/stops/{id}', [\App\Http\Controllers\API\VanController::class, 'saveStop']);
        Route::delete('/van/stops/{id}', [\App\Http\Controllers\API\VanController::class, 'retireStop']);
        // The rider's "Meet the van" card — his side of the same trip.
        Route::get('/van/meet-card', [\App\Http\Controllers\API\VanController::class, 'meetCard']);
        // The store's live van panel (mobile store mode). ETAs are cached-or-
        // approximated and warmed out of band — this polls and must never block.
        Route::get('/van/store-panel', [\App\Http\Controllers\API\VanController::class, 'storePanel']);
        // Just "who drives a van today" — cheap enough for the store's orders
        // screen to ask on every load so it can offer the load scan on the row.
        Route::get('/van/drivers', [\App\Http\Controllers\API\VanController::class, 'drivers']);

        // 🏍️ "My vehicle" (Aug-2026) — the rider's OWN machine: what he has, its
        // odometer and service state, his fuel/maintenance history and the dated
        // condition photos. SELF-SCOPED, so no permission key: it resolves the
        // caller's own assignment and can return nothing else. Everyone else's
        // costs stay behind `view_bike_costs`, which this never touches.
        Route::get('/my-vehicle', [\App\Http\Controllers\API\MyVehicleController::class, 'show']);
        // ⭐ His OWN bike's meter for a day the van owns his attendance readings. Same
        //   writer as the manager's Vehicles page (VehicleService::saveMeterLog); the
        //   gates (his machine, his window, plausible, his own row) are in the controller.
        Route::post('/my-vehicle/meter', [\App\Http\Controllers\API\MyVehicleController::class, 'saveMeter']);
        
        // Orders - reusing existing filter endpoint (enhanced with customer order count)
        Route::get('/orders', [\App\Http\Controllers\CRM\OrderController::class, 'filter']);
        Route::get('/orders/{id}', [\App\Http\Controllers\API\RiderController::class, 'getOrderDetails']);
        Route::post('/orders/{id}/mark-delivered', [\App\Http\Controllers\API\RiderController::class, 'markOrderDelivered']);
        // Phase 3 delivery package-scan (default off): read config flags + verify a scanned QR.
        Route::get('/delivery-scan-config', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryScanConfig']);
        Route::get('/verify-delivery-scan', [\App\Http\Controllers\API\RiderController::class, 'verifyDeliveryScan']);
        // Best-effort audit stamp: rider completed the delivery package scan (proof + time).
        Route::post('/orders/{id}/delivery-scan-mark', [\App\Http\Controllers\API\RiderController::class, 'deliveryScanMark']);
        // Scan help (Aug-2026): a rider who cannot scan every packet asks a manager to approve a
        // short delivery, instead of the packet count being silently ignored. The two /scan-help
        // collection routes are declared BEFORE the {id} forms purely for readability — the
        // prefixes differ, so there is no capture risk either way.
        Route::get('/scan-help/pending', [\App\Http\Controllers\API\RiderController::class, 'scanHelpPending']);
        Route::post('/scan-help/decide', [\App\Http\Controllers\API\RiderController::class, 'scanHelpDecide']);
        Route::post('/orders/{id}/scan-help', [\App\Http\Controllers\API\RiderController::class, 'scanHelpRequest']);
        Route::get('/orders/{id}/scan-help', [\App\Http\Controllers\API\RiderController::class, 'scanHelpStatus']);
        // Receipt printout field config for the app (which fields/text to print).
        Route::get('/receipt-config', [\App\Http\Controllers\API\RiderController::class, 'getReceiptConfig']);
        // Dispatch package-scan (store hand-over): scan each packet, persistent "ready" tick.
        Route::post('/orders/{id}/dispatch-scan', [\App\Http\Controllers\API\RiderController::class, 'dispatchScan']);
        Route::post('/orders/{id}/dispatch-scan-clear', [\App\Http\Controllers\API\RiderController::class, 'dispatchScanClear']);
        Route::post('/orders/{id}/change-payment-method', [\App\Http\Controllers\API\RiderController::class, 'changePaymentMethod']);
        Route::post('/orders/{id}/mark-online-message-sent', [\App\Http\Controllers\API\RiderController::class, 'markOnlineMessageSent']);
        
        // Customer verified location
        Route::post('/customers/{customerId}/set-verified-location', [\App\Http\Controllers\API\RiderController::class, 'setCustomerVerifiedLocation']);
        // Verified-pin lock: store/web grants a rider a time-boxed unlock window.
        Route::post('/customers/{customerId}/unlock-verified-pin', [\App\Http\Controllers\API\RiderController::class, 'unlockCustomerVerifiedPin']);
        // Aug-2026 — the rider ASKS for a locked pin to be unlocked (one tap on his order screen).
        Route::post('/customers/{customerId}/request-pin-unlock', [\App\Http\Controllers\API\RiderController::class, 'requestCustomerPinUnlock']);
        // Aug-2026 — store banner ✕: clear a rider's unlock request without unlocking.
        Route::post('/customers/{customerId}/dismiss-pin-unlock-request', [\App\Http\Controllers\API\RiderController::class, 'dismissCustomerPinUnlockRequest']);
        // Generic per-order live flags the rider app polls while an order is open
        // (verified-pin lock now; scan/dispatch-lock later — shared mechanism).
        Route::get('/orders/{orderId}/live-flags', [\App\Http\Controllers\API\RiderController::class, 'getOrderLiveFlags']);

        // Quick verify location from address (Store Mode)
        // PREVIEW (Jul-28-2026): geocodes the written address and RETURNS the
        // candidate pin without saving anything. The app shows it on the map and
        // the human confirms — which is what makes the saved pin a real
        // verification rather than a machine guess promoted into the verified
        // column. The save then goes through the normal
        // /customers/{id}/set-verified-location path.
        Route::post('/store/orders/{orderId}/geocode-preview', [\App\Http\Controllers\API\RiderController::class, 'previewLocationFromAddress']);
        // Legacy blind-save, kept because phones on the old APK still call it
        // until everyone updates.
        Route::post('/store/orders/{orderId}/set-verified-location', [\App\Http\Controllers\API\RiderController::class, 'setVerifiedLocationFromAddress']);
        
        // Ledger & Settlements
        Route::get('/ledger', [\App\Http\Controllers\API\RiderController::class, 'getLedger']);
        Route::get('/ledger/outstanding-invoices', [\App\Http\Controllers\API\RiderController::class, 'getOutstandingInvoices']);
        Route::get('/ledger/expense-categories', [\App\Http\Controllers\API\RiderController::class, 'getExpenseCategories']);
        Route::post('/ledger/settle', [\App\Http\Controllers\API\RiderController::class, 'settleInvoices']);
        Route::post('/ledger/settle-short-cash', [\App\Http\Controllers\API\RiderController::class, 'settleShortCash']);
        
        // Overall Ledger
        Route::get('/overall-ledger', [\App\Http\Controllers\API\RiderController::class, 'getOverallLedger']);
        
        // Daily Closing (Invoice Tracker)
        Route::get('/daily-closing', [\App\Http\Controllers\API\RiderController::class, 'getDailyClosing']);
        Route::post('/daily-closing/approve/{id}', [\App\Http\Controllers\API\RiderController::class, 'approveDailyClosingSettlement']);
        Route::post('/daily-closing/reject/{id}', [\App\Http\Controllers\API\RiderController::class, 'rejectDailyClosingSettlement']);
        // [1B] Delivered-but-unposted reconciliation: surface + repost silent invoice-post failures.
        Route::get('/daily-closing/unposted-invoices', [\App\Http\Controllers\API\RiderController::class, 'getUnpostedInvoices']);
        Route::post('/daily-closing/repost-invoice/{orderId}', [\App\Http\Controllers\API\RiderController::class, 'repostOrderInvoice']);
        
        // Shift management (Store → Shifts; assign/cancel/templates/riders gated by manage_shifts)
        Route::get('/shifts/templates', [\App\Http\Controllers\API\RiderController::class, 'getShiftTemplatesMobile']);
        Route::get('/shifts/riders', [\App\Http\Controllers\API\RiderController::class, 'getStoreShiftRiders']);
        Route::get('/shifts/rider-month', [\App\Http\Controllers\API\RiderController::class, 'getRiderShiftMonth']);
        Route::get('/shifts/history', [\App\Http\Controllers\API\RiderController::class, 'getRiderShiftHistory']);
        Route::post('/shifts/create-template', [\App\Http\Controllers\API\RiderController::class, 'createShiftTemplateMobile']);
        Route::post('/shifts/update-template/{id}', [\App\Http\Controllers\API\RiderController::class, 'updateShiftTemplateMobile']);
        Route::post('/locations/create', [\App\Http\Controllers\API\RiderController::class, 'createLocationMobile']);
        Route::post('/shifts/assign', [\App\Http\Controllers\API\RiderController::class, 'assignShiftMobile']);
        Route::post('/shifts/cancel', [\App\Http\Controllers\API\RiderController::class, 'cancelShiftMobile']);
        Route::post('/shifts/toggle-day-tag', [\App\Http\Controllers\API\RiderController::class, 'toggleDayTagMobile']);
        Route::post('/shifts/day-tag-range', [\App\Http\Controllers\API\RiderController::class, 'setDayTagRangeMobile']); // 🚫 Not required range
        Route::post('/shifts/update-phone', [\App\Http\Controllers\API\RiderController::class, 'updateShiftRiderPhone']);
        /**
         * 👥 Users list (Sep-2026) — the shared Shift Planner + Attendance roster.
         * ⚠ NOT gated by `manage_shifts`: the gate is `manage_user_roster` (Shabib +
         *   Taimur), checked inside the controller so the refusal carries a message.
         */
        Route::get('/shifts/users-list', [\App\Http\Controllers\API\RiderController::class, 'shiftUsersListMobile']);
        Route::post('/shifts/users-list/toggle', [\App\Http\Controllers\API\RiderController::class, 'toggleShiftUserMobile']);
        Route::post('/shift/acknowledge', [\App\Http\Controllers\API\RiderController::class, 'acknowledgeShift']);
        /**
         * ⏳ SHIFT CHANGE APPROVALS (Sep-2026) — the phone twin of the desk's corner banner.
         * ⚠ Deliberately NOT under a `manage_shifts` check: the queue is answered by LADDER
         *   POSITION (see ShiftAuthorityService), and it returns an empty list to anyone who
         *   has nothing to answer, so the banner renders nothing for them.
         */
        Route::get('/shift-approvals', [\App\Http\Controllers\API\RiderController::class, 'shiftApprovalsMobile']);
        Route::post('/shift-approvals/{id}/approve', [\App\Http\Controllers\API\RiderController::class, 'approveShiftRequestMobile'])->where('id', '[0-9]+');
        Route::post('/shift-approvals/{id}/decline', [\App\Http\Controllers\API\RiderController::class, 'declineShiftRequestMobile'])->where('id', '[0-9]+');
        // Rider-facing (self-only, no manage_shifts): floating banner + My Shift view
        Route::get('/shift/pending', [\App\Http\Controllers\API\RiderController::class, 'getPendingShiftAck']);
        Route::get('/shift/mine', [\App\Http\Controllers\API\RiderController::class, 'getMyShift']);

        // Attendance
        Route::get('/attendance/today', [\App\Http\Controllers\API\RiderController::class, 'getTodayAttendance']);
        Route::post('/attendance/check-in', [\App\Http\Controllers\API\RiderController::class, 'checkIn']);
        Route::post('/attendance/check-out', [\App\Http\Controllers\API\RiderController::class, 'checkOut']);
        Route::post('/attendance/confirm-cash', [\App\Http\Controllers\API\RiderController::class, 'confirmCash']);
        // 🏦 The till keeper's count of a COMPANY account (sibling of confirm-cash, which is
        // about the rider's own float). Gated inside the controller on the keeper tag.
        Route::post('/till/count', [\App\Http\Controllers\API\RiderController::class, 'tillCount']);
        Route::post('/attendance/upload-meter-picture', [\App\Http\Controllers\API\RiderController::class, 'uploadMeterPicture']);
        Route::post('/attendance/home-meter', [\App\Http\Controllers\API\RiderController::class, 'submitHomeMeter']); // U4 going-home meter
        Route::get('/attendance/home-journey', [\App\Http\Controllers\API\RiderController::class, 'getHomeJourneyStatus']); // U4 banner poll
        Route::post('/attendance/home-start-meter', [\App\Http\Controllers\API\RiderController::class, 'submitHomeStartMeter']); // U5 morning start-at-home meter
        Route::get('/attendance/monthly', [\App\Http\Controllers\API\RiderController::class, 'getMonthlyAttendance']);
        Route::get('/attendance/petrol-rate', [\App\Http\Controllers\API\RiderController::class, 'getPetrolRate']);
        Route::post('/attendance/petrol-rate', [\App\Http\Controllers\API\RiderController::class, 'setPetrolRate']);
        
        // Requests
        Route::get('/requests/categories', [\App\Http\Controllers\API\RiderController::class, 'getRequestCategories']);
        Route::get('/requests', [\App\Http\Controllers\API\RiderController::class, 'getRequests']);
        Route::post('/requests', [\App\Http\Controllers\API\RiderController::class, 'createRequest']);

        // Leave approvals (manager) — gated by the 'approve_leaves' mobile permission;
        // reuse the exact web leave logic (AttendanceController) via thin wrappers.
        Route::get('/leaves/pending', [\App\Http\Controllers\API\RiderController::class, 'mobilePendingLeaves']);
        Route::post('/leaves/{id}/approve', [\App\Http\Controllers\API\RiderController::class, 'mobileApproveLeave']);
        Route::post('/leaves/{id}/reject', [\App\Http\Controllers\API\RiderController::class, 'mobileRejectLeave']);
        
        // Salary
    Route::get('/salary', [\App\Http\Controllers\API\RiderController::class, 'getSalaryInfo']);
    Route::get('/salary/slips/{slipId}', [\App\Http\Controllers\API\RiderController::class, 'getSalarySlipDetails']);

        // Payroll (Phase G — new engine; manager grid + rider self-view)
        Route::prefix('payroll')->group(function () {
            Route::get('/my', [\App\Http\Controllers\API\PayrollController::class, 'mySalary']);            // rider self
            Route::get('/leave-history', [\App\Http\Controllers\API\PayrollController::class, 'leaveHistory']); // self or (manager) any user
            Route::get('/month', [\App\Http\Controllers\API\PayrollController::class, 'managerMonth']);     // manager grid
            Route::post('/set-salary', [\App\Http\Controllers\API\PayrollController::class, 'managerSetSalary']);
            Route::post('/give-advance', [\App\Http\Controllers\API\PayrollController::class, 'managerGiveAdvance']);
            Route::post('/pay', [\App\Http\Controllers\API\PayrollController::class, 'managerPay']);
            // Leave actions on the phone (Sep-2026): the same decisions as the web panel.
            Route::post('/decide-leave-action', [\App\Http\Controllers\API\PayrollController::class, 'decideLeaveAction']);
            Route::post('/settle-absence', [\App\Http\Controllers\API\PayrollController::class, 'settleAbsence']);
            Route::post('/dismiss-absence-alert', [\App\Http\Controllers\API\PayrollController::class, 'dismissAbsenceAlert']);
            // Day review (Sep-2026): the same queue and verdicts as the web bulb.
            Route::get('/day-reviews', [\App\Http\Controllers\API\PayrollController::class, 'dayReviewsPending']);
            Route::post('/day-reviews/record', [\App\Http\Controllers\API\PayrollController::class, 'dayReviewRecord']);
        });
    
    // Approvals (Admin/Manager users)
    Route::prefix('approvals')->group(function () {
        Route::get('/', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'index']);
        Route::get('/summaries', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'summaries']);
        Route::get('/online', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'onlineOnly']); // ⭐ Fast endpoint for online only
        // Bank Balances (read-only) for the mobile Banks view — same gate as
        // Online Approvals (view_online_approvals / view_approvals).
        Route::get('/bank-balances', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'bankBalances']);
        Route::get('/bank-balances/{id}/transactions', [\App\Http\Controllers\API\ApprovalsAPIController::class, 'bankTransactions'])->whereNumber('id');
        // Read-only payment-proof detail (WhatsApp screenshot / bank email) for an
        // order — powers the proof popup in the mobile Online Approvals screen,
        // mirroring the web /admin/payments/order/{id}/signals panel.
        Route::get('/order/{orderId}/payment-signals', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'forOrder']);
        // Jun-2026 — Phase 2: one-time "balancing discount" (same controller as web).
        Route::post('/order/{orderId}/balance-discount', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'applyBalanceDiscount']);
        Route::post('/order/{orderId}/balance-discount/remove', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'removeBalanceDiscount']);
        // Aug-2026 — "Wrong customer — remove": detach a payment from an order.
        // SAME controller method as the web proof panel, so the two surfaces can
        // never disagree about what removal means.
        Route::post('/signal/{signalId}/unmark', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'unmark'])
            ->whereNumber('signalId');
        // Aug-2026 — "customer paid more than the invoice". SAME controller as the
        // web approvals screen, so the two surfaces can never disagree about what
        // counts as an overpayment or what happens to it.
        Route::get('/order/{orderId}/overpay', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayInfo'])
            ->whereNumber('orderId');
        Route::post('/order/{orderId}/overpay-to-balance', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayToBalance'])
            ->whereNumber('orderId');
        Route::post('/order/{orderId}/overpay-to-tip', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayToTip'])
            ->whereNumber('orderId');
        Route::post('/signal/{signalId}/delete-manual', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'deleteManualProof'])
            ->whereNumber('signalId');
        Route::post('/overpay-batch', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayBatch']);
    });
    
    // Mobile Permissions
    Route::get('/permissions', [\App\Http\Controllers\API\RiderController::class, 'getMobilePermissions']);
    
    // Active Users (for creating requests for others)
    Route::get('/users/active', [\App\Http\Controllers\API\RiderController::class, 'getActiveUsers']);
    
    // Store Mode - Open Orders
    Route::get('/store/order-statuses', [\App\Http\Controllers\API\RiderController::class, 'getOrderStatuses']);
    Route::get('/store/open-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrders']);
    Route::get('/store/open-orders-light', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrdersLight']); // Lightweight for list
    Route::get('/store/open-orders/{id}/details', [\App\Http\Controllers\API\RiderController::class, 'getStoreOpenOrderDetails']); // Full details when expanded
    Route::get('/store/delivered-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreDeliveredOrders']); // ⭐ Delivered orders grouped by date
    Route::get('/store/dispatch-report', [\App\Http\Controllers\API\RiderController::class, 'getDateDeliveryReport']); // ⭐ Dispatch tracker report by date
    Route::get('/store/rider-reports', [\App\Http\Controllers\CRM\RiderReportsController::class, 'apiIndex']); // ⭐ Rider Reports (⚠ Issues + Report Card), real-time, mobile-permission gated
    Route::get('/store/rider-timeline', [\App\Http\Controllers\CRM\RiderReportsController::class, 'apiTimeline']); // ⭐ Per-rider day timeline
    // Day Review (Jul-2026) — mobile parity with the web tab that replaced History/Dispatch/Issues.
    // Base = view_dispatch_tracker OR view_rider_reports (mobile grants); forensics stripped without the latter.
    Route::get('/store/day-review', [\App\Http\Controllers\CRM\RiderDayReviewController::class, 'apiDay']);
    Route::get('/store/day-review/rider', [\App\Http\Controllers\CRM\RiderDayReviewController::class, 'apiRider']);
    // Fleet & Fuel (Jul-2026) — monthly bike cost + the fuel/maintenance approval
    // queue with the month's context. Mobile grants: view_rider_reports OR view_expenses.
    Route::get('/store/fleet', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiMonth']);
    Route::get('/store/fleet/rider', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiRider']);
    // Service schedule from the app — per-bike and company-wide. Both need the
    // MOBILE `manage_bike_service` grant on top of Bikes read access.
    // 🏍️ The machines themselves (Aug-2026). Reading needs the `view_bike_costs`
    // mobile grant, exactly like the Bikes screen. EDITING a machine (name, reg,
    // retire) and the day-override still stay on the web; the writes the phone has
    // are condition photos (Aug-2026) and — since Sep-2026 — the HANDOVER itself.
    // `/days` is the month's kilometres, day by day.
    // Which machine a claim will land on — used by every fuel/maintenance form.
    // ⚠ ABOVE '/vehicles/{id}': 'vehicle-for-user' is its own path, not an id.
    Route::get('/store/fleet/vehicle-for-user', [\App\Http\Controllers\CRM\VehicleController::class, 'forUser']);
    Route::get('/store/fleet/vehicles', [\App\Http\Controllers\CRM\VehicleController::class, 'apiIndex']);
    // ⚠ ABOVE '/vehicles/{id}' — 'roster' is a path, not an id.
    Route::get('/store/fleet/roster', [\App\Http\Controllers\CRM\VehicleController::class, 'apiRoster']);
    // 🏠 RIDER HOME LOCATION (Sep-2026) — the phone's half of the shared engine. These hit the
    //    SAME controller + RiderHomePinService as the web Bikes tab, so a pin saved on either
    //    surface is read identically by the other. Reading needs the Bikes mobile key; changing
    //    additionally needs `assign_vehicles`, both checked inside the controller.
    // ⚠ ABOVE '/vehicles/{id}' — 'home-pin' is a path, not an id.
    Route::get('/store/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'apiHomePinShow'])->where('userId', '[0-9]+');
    Route::post('/store/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'apiHomePinSave'])->where('userId', '[0-9]+');
    Route::delete('/store/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'apiHomePinClear'])->where('userId', '[0-9]+');
    // ⭐ 🔁 RIDER HANDOVER REQUESTS (Sep-2026) — "give me the van" / "take it back".
    //   A request MOVES NOTHING; approving runs the same VehicleService::assign()
    //   the fleet screen runs. The rider routes are SELF-SCOPED and need no
    //   permission key (like /my-vehicle); the approver routes are gated on
    //   `assign_vehicles` inside the controller.
    // ⚠ ABOVE any '{id}' pattern in this prefix — 'options'/'mine'/'pending' are paths.
    Route::get('/vehicle-request/options', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'options']);
    Route::get('/vehicle-request/mine', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'mine']);
    Route::post('/vehicle-request', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'raise']);
    Route::post('/vehicle-request/{id}/cancel', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'cancel']);
    // Approver side — store mode banner + decisions.
    Route::get('/store/vehicle-requests', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'pending']);
    Route::post('/store/vehicle-requests/{id}/approve', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'approve']);
    Route::post('/store/vehicle-requests/{id}/reject', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'reject']);
    Route::get('/store/fleet/vehicles/{id}', [\App\Http\Controllers\CRM\VehicleController::class, 'apiShow']);
    Route::get('/store/fleet/vehicles/{id}/days', [\App\Http\Controllers\CRM\VehicleController::class, 'apiDays']);
    // ⭐⭐ The per-vehicle per-job schedule editor, on the phone too.
    Route::get('/store/fleet/vehicles/{id}/schedule', [\App\Http\Controllers\CRM\VehicleController::class, 'apiSchedule'])->where('id', '[0-9]+');
    Route::post('/store/fleet/vehicles/{id}/schedule', [\App\Http\Controllers\CRM\VehicleController::class, 'apiSaveSchedule'])->where('id', '[0-9]+');
    // Condition photos from the phone — the handover happens in the yard, not at a
    // desk. Gated on view_bike_costs (open the screen) AND assign_vehicles (write).
    Route::post('/store/fleet/vehicles/{id}/photos', [\App\Http\Controllers\CRM\VehicleController::class, 'apiAddPhotos']);
    // ⭐ HANDOVER FROM THE PHONE (Sep-2026). Every one of these is a thin delegate to
    //   the web method — same validation, same displaced-rider settle, same logging.
    //   Gates: view_bike_costs (open Bikes) AND assign_vehicles (move machines).
    //   The preview endpoints are what let the sheet state the consequences BEFORE
    //   the manager commits, exactly as the web modal does.
    Route::get('/store/fleet/vehicles/{id}/preview-assign', [\App\Http\Controllers\CRM\VehicleController::class, 'apiPreviewAssign']);
    Route::post('/store/fleet/vehicles/{id}/assign', [\App\Http\Controllers\CRM\VehicleController::class, 'apiAssign']);
    Route::get('/store/fleet/vehicles/{id}/preview-release', [\App\Http\Controllers\CRM\VehicleController::class, 'apiPreviewRelease']);
    Route::post('/store/fleet/vehicles/{id}/release', [\App\Http\Controllers\CRM\VehicleController::class, 'apiRelease']);
    // 🛢 Service-due banners. ⚠ NOT under the fleet permission — a RIDER has no
    // fleet access yet must be told his own machine is due; the audience rule
    // lives in BikeServiceAlerts::forUser().
    Route::get('/service-alerts', [\App\Http\Controllers\CRM\VehicleController::class, 'serviceAlerts']);
    Route::post('/service-alerts/dismiss', [\App\Http\Controllers\CRM\VehicleController::class, 'dismissServiceAlert']);

    // 🛠 BIKE TICKETS (Sep-2026). ⚠ NOT under the fleet permission, for the same reason
    // the service-alert routes are not: a RIDER holds no fleet key at all and must be
    // able to report a fault on the machine he is riding. Who may see, reply to and
    // close what is decided inside VehicleTicketService, not by a route gate.
    /**
     * 🛠 THE ISSUES BOARD (Sep-15 2026) — the fleet's open problems grouped BY MACHINE, for the
     * third Bikes tab. Same audience rule as the routes below: decided in VehicleIssueBoard.
     * ⚠ Its own path, NOT `/vehicle-tickets/board` — that would sit under the `{id}` route just
     *   below and, being non-numeric, only avoids being swallowed by the `[0-9]+` constraint.
     *   A separate noun is clearer than a rule someone has to remember.
     */
    Route::get('/vehicle-issues', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiBoard']);

    Route::get('/vehicle-tickets', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiIndex']);
    Route::post('/vehicle-tickets', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiStore']);
    // Drives the floating banner — polled, so it stays a cheap summary.
    Route::get('/vehicle-tickets/alerts', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiAlerts']);
    Route::get('/vehicle-tickets/{id}', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiShow'])->where('id', '[0-9]+');
    Route::post('/vehicle-tickets/{id}/reply', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiReply'])->where('id', '[0-9]+');
    Route::post('/vehicle-tickets/{id}/close', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiClose'])->where('id', '[0-9]+');
    Route::post('/vehicle-tickets/{id}/reopen', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiReopen'])->where('id', '[0-9]+');
    Route::post('/vehicle-tickets/{id}/read', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'apiMarkRead'])->where('id', '[0-9]+');

    // 🔧 WORKSHOP VISITS (Sep-2026). ⚠ NOT under the fleet permission: `pending` and
    // `accept` must reach the RIDER, who holds no key and is the person the instruction
    // is addressed to. `pending` is self-scoped to Auth::id(); scheduling and completing
    // check `schedule_workshop` inside WorkshopVisitService.
    Route::get('/workshop-visits', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiIndex']);
    Route::post('/workshop-visits', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiStore']);
    Route::get('/workshop-visits/pending', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiPending']);
    Route::get('/workshop-visits/alerts', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiAlerts']);
    Route::get('/workshop-visits/warnings', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiWarnings']);
    // ⭐ Phase 3 — the RIDER answering "did it get done?" on his own visit. Self-scoped
    // to Auth::id(), so no permission and nothing to leak.
    Route::get('/workshop-visits/outcome', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiOutcome']);
    /**
     * ⏳ APPROVAL (6-Sep ruling). A booked workshop day is a REQUEST until a SHIFT PLANNER
     *    approves it; only then is the rider's day pinned and only then is he told.
     * ⚠ No middleware here either — `manage_shifts` is checked inside the service, which is
     *   the one place that answers "is this person a planner?" for both surfaces.
     */
    Route::get('/workshop-visits/approvals', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiApprovals']);
    // 📍 Add a workshop from the booking sheet, without leaving it. Coordinates required —
    //    the phone sends its own. Gate: schedule_workshop OR manage_shifts, in the controller.
    Route::post('/workshop-visits/locations', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiAddWorkshopLocation']);
    Route::post('/workshop-visits/{id}/approve', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiApprove'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/decline', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiDecline'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/accept', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiAccept'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/cancel', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiCancel'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/done', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiDone'])->where('id', '[0-9]+');
    /**
     * 🚦 THE TRIP (10-Sep-2026). `depart` is the rider's "Workshop jaa raha hoon"; `live` is the
     *    ONE list the store banner reads. Arrival needs no route — the geofence stamps it from
     *    the heartbeat the phone already sends.
     */
    Route::post('/workshop-visits/{id}/depart', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiDepart'])->where('id', '[0-9]+');
    Route::get('/workshop-visits/live', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiLive']);
    /**
     * 📍 …EXCEPT WHERE THERE IS NOTHING TO GEOFENCE AGAINST (11-Sep-2026). A workshop entered as
     *    free text has no pin, so the rider is asked once — and "yes" both stamps the arrival and
     *    PINS the place, after which that workshop is automatic for everyone, forever.
     * ⚠ "no" is a real answer with its own route: silence must never be read as yes, because a
     *   wrong yes pins a workshop at the wrong place for everybody.
     */
    Route::get('/workshop-visits/{id}/types', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiVisitTypes'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/arrived-here', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiArrivedHere'])->where('id', '[0-9]+');
    Route::post('/workshop-visits/{id}/not-here', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiNotHere'])->where('id', '[0-9]+');
    /**
     * ⏰ "Baad mein (6h)" on the approval card — per USER, never a decision. The visit stays
     *    open for every other planner and comes back to this one in six hours.
     */
    Route::post('/workshop-visits/{id}/snooze', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'apiSnooze'])->where('id', '[0-9]+');
    Route::post('/store/fleet/mark-serviced', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiMarkServiced']);
    // ✏️ Correct / remove a service record from the phone — same right as recording one.
    Route::post('/store/fleet/service-records/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiAmendServiceRecord'])->where('id', '[0-9]+');
    Route::delete('/store/fleet/service-records/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiDeleteServiceRecord'])->where('id', '[0-9]+');
    // ✏️ Correct the odometer / job on a maintenance CLAIM. Money fields stay locked.
    Route::post('/store/fleet/claim-readings/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiCorrectClaimReading'])->where('id', '[0-9]+');
    // 🧾 "Which recorded service is this bill for?" — the picker's list. Own by default;
    //    another rider needs manage_bike_service.
    Route::get('/store/fleet/unbilled-services', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiUnbilledServices']);
    /**
     * ⚠ RETIRED UI, LIVE ROUTE (Sep-2026). The single fleet-wide number and the
     *   single-number per-bike prompt are gone from both apps — schedules are per job,
     *   per class, with per-vehicle exceptions. These two stay reachable so an APK built
     *   before that change does not crash on a 404; the value they write is now only a
     *   last-resort fallback nothing consults while a job carries a standard.
     */
    Route::post('/store/fleet/default-interval', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiSetDefaultInterval']);
    Route::get('/store/fleet/interval-overrides', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiIntervalOverrides']);

    /**
     * ⭐⭐ MAINTENANCE TYPES FROM THE PHONE (owner, 10-Sep-2026): "can Qasim create it
     *    from the mobile?" — yes. He owns maintenance and works in frozen mode on a
     *    handset, so the van's job list has to be buildable there. Same controller
     *    methods as the web, gated on the mobile half of `manage_bike_service`.
     */
    Route::get('/store/fleet/maintenance-types', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiMaintenanceTypes']);
    Route::post('/store/fleet/maintenance-types', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiSaveMaintenanceType']);
    Route::post('/store/fleet/maintenance-types/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiSaveMaintenanceType'])->where('id', '[0-9]+');
    Route::delete('/store/fleet/maintenance-types/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiDeleteMaintenanceType'])->where('id', '[0-9]+');
    Route::get('/store/fleet/maintenance-types/{id}/exceptions', [\App\Http\Controllers\CRM\FleetFuelController::class, 'apiTypeExceptions'])->where('id', '[0-9]+');
    Route::get('/store/cancelled-orders', [\App\Http\Controllers\API\RiderController::class, 'getStoreCancelledOrders']); // ⭐ Cancelled orders grouped by date
    Route::get('/store/delivered-quantities-tree', [\App\Http\Controllers\API\RiderController::class, 'getDeliveredQuantitiesTree']); // ⭐ Delivered quantities with drill-down (lazy)
    Route::get('/store/delivered-quantities-full-tree', [\App\Http\Controllers\API\RiderController::class, 'getDeliveredQuantitiesFullTree']); // ⭐ Full tree for instant access (last 10 days)
    Route::get('/store/riders', [\App\Http\Controllers\API\RiderController::class, 'getActiveRiders']);
    Route::post('/store/assign-rider', [\App\Http\Controllers\API\RiderController::class, 'assignRiderToOrder']);
    Route::post('/store/update-status', [\App\Http\Controllers\API\RiderController::class, 'updateOrderStatus']);
    Route::post('/store/update-packets', [\App\Http\Controllers\API\RiderController::class, 'updatePacketInfo']);
    Route::post('/store/update-order-note', [\App\Http\Controllers\API\RiderController::class, 'updateOrderNote']); // ⭐ Add order instructions
    Route::post('/store/add-customer-note', [\App\Http\Controllers\API\RiderController::class, 'addCustomerNote']); // ⭐ Pin note to customer profile
    Route::post('/store/update-payment-method', [\App\Http\Controllers\API\RiderController::class, 'updatePaymentMethod']); // ⭐ Change payment type
    Route::post('/store/update-delivery-priorities', [\App\Http\Controllers\API\RiderController::class, 'updateDeliveryPriorities']); // ⭐ Set delivery sequence
    
    // Store Mode - Delivery Regions
    Route::get('/store/delivery-regions', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRegionsWithStats']);
    Route::get('/store/region-stats', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getStats']);
    Route::get('/store/rider-assignments', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRiderAssignments']);
    Route::get('/store/active-riders', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getActiveRiders']);
    Route::post('/store/assign-rider-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRiderAssignment']);
    Route::delete('/store/remove-rider-region/{id}', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'removeRiderAssignment']);
    Route::post('/store/set-order-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'setOrderRegion']);
    Route::post('/store/set-customer-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'setCustomerRegion']);
    Route::post('/store/auto-assign-riders', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'autoAssignRiders']);

    // Open Orders → "Get Customer Locations" (mobile). Same controller as web;
    // stateless live recompute, no tracking table.
    Route::get('/store/location-request/eligible', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'eligible']);
    Route::post('/store/location-request/send', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'send']);
    Route::get('/store/location-request/settings', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'settings']);
    Route::post('/store/location-request/settings', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'saveSettings']);
    Route::post('/store/batch-detect-regions', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'batchDetect']);
    Route::post('/store/redetect-regions', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'redetectAll']);
    Route::post('/store/save-region-polygon', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRegionPolygon']);
    Route::post('/store/save-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRegion']);
    
    // Store Mode - WhatsApp Message Templates
    Route::get('/store/whatsapp-templates', [\App\Http\Controllers\API\RiderController::class, 'getWhatsappTemplates']);
    Route::post('/store/whatsapp-templates', [\App\Http\Controllers\API\RiderController::class, 'saveWhatsappTemplate']);
    
    // Store Mode - Campaign Management
    Route::get('/store/campaigns', [\App\Http\Controllers\API\RiderController::class, 'getCampaigns']);
    Route::post('/store/campaigns', [\App\Http\Controllers\API\RiderController::class, 'createCampaign']);
    Route::post('/store/campaigns/preview', [\App\Http\Controllers\API\RiderController::class, 'previewCampaign']);
    Route::get('/store/campaigns/cities', [\App\Http\Controllers\API\RiderController::class, 'getCampaignCities']);
    Route::get('/store/campaigns/qurbani-years', [\App\Http\Controllers\API\RiderController::class, 'getCampaignQurbaniYears']);
    // Combined per-template results. MUST sit above /{id} or 'template-results'
    // is captured as a campaign id.
    Route::get('/store/campaigns/template-results', [\App\Http\Controllers\API\RiderController::class, 'getCampaignTemplateResults']);
    Route::get('/store/campaigns/{id}', [\App\Http\Controllers\API\RiderController::class, 'getCampaignDetail']);
    Route::post('/store/campaigns/{id}/add-customers', [\App\Http\Controllers\API\RiderController::class, 'addCampaignCustomers']);
    Route::post('/store/campaigns/{id}/refresh', [\App\Http\Controllers\API\RiderController::class, 'refreshCampaign']);
    Route::post('/store/campaigns/{id}/refresh-dedup', [\App\Http\Controllers\API\RiderController::class, 'refreshCampaignDedup']);
    Route::post('/store/campaigns/{id}/end', [\App\Http\Controllers\API\RiderController::class, 'endCampaign']);
    Route::get('/store/campaigns/{id}/stats', [\App\Http\Controllers\API\RiderController::class, 'getCampaignStats']);
    Route::post('/store/campaigns/{id}/send-bulk', [\App\Http\Controllers\API\RiderController::class, 'sendCampaignBulk']);
    // Send control: allowance + run progress, plus background start/pause so a
    // long campaign doesn't need the phone awake and on-screen.
    Route::get('/store/campaigns/{id}/send-status', [\App\Http\Controllers\API\RiderController::class, 'getCampaignSendStatus']);
    Route::post('/store/campaigns/{id}/send-background', [\App\Http\Controllers\API\RiderController::class, 'startCampaignBackgroundSend']);
    Route::post('/store/campaigns/{id}/send-pause', [\App\Http\Controllers\API\RiderController::class, 'pauseCampaignSend']);
    Route::post('/store/campaigns/{id}/customers/{customerId}/mark-sent', [\App\Http\Controllers\API\RiderController::class, 'markCampaignCustomerSent']);
    Route::post('/store/campaigns/{id}/customers/{customerId}/mark-skipped', [\App\Http\Controllers\API\RiderController::class, 'markCampaignCustomerSkipped']);
    
    // Store Mode - Open Order Quantities
    Route::get('/store/open-quantities', [\App\Http\Controllers\API\RiderController::class, 'getOpenOrderQuantities']);
    // Per-user display sort preferences (sorting happens on the phone; the tree
    // endpoint below never reads these). Static paths — keep above any dynamic ones.
    Route::get('/store/open-quantities/sort-prefs', [\App\Http\Controllers\API\RiderController::class, 'getQuantitiesSortPrefs']);
    Route::post('/store/open-quantities/sort-prefs', [\App\Http\Controllers\API\RiderController::class, 'saveQuantitiesSortPrefs']);
    Route::get('/store/open-quantities-tree', [\App\Http\Controllers\API\RiderController::class, 'getOpenOrderQuantitiesTree']);
    // Fixed 3-level hierarchy (attribute_1 -> attribute_2 -> attribute_3 -> product -> orders) for mobile
    Route::get(
        '/store/open-quantities-tree-fixed',
        [\App\Http\Controllers\API\OpenQuantitiesFixedController::class, 'getFixedThreeLevelTree']
    );
    
    // 🐔 La Carne — chicken supplier board. The SAME controller serves the web
    // page (routes/web.php, prefix 'lacarne'), so both surfaces share one set of
    // numbers and one permission rule (access_lacarne / manage_lacarne_history).
    // Tiny poll the rider app uses to decide whether to show the La Carne TAB.
    // Static path — keep above any dynamic ones.
    Route::get('/store/lacarne/access', [\App\Http\Controllers\CRM\LaCarneController::class, 'access_check']);
    Route::get('/store/lacarne/board', [\App\Http\Controllers\CRM\LaCarneController::class, 'board']);
    Route::post('/store/lacarne/photos', [\App\Http\Controllers\CRM\LaCarneController::class, 'addPhotos']);
    Route::post('/store/lacarne/photos/{photoId}/delete', [\App\Http\Controllers\CRM\LaCarneController::class, 'deletePhoto']);

    // Line Item Status Management (mobile app only - uses token auth)
    Route::post('/orders/{orderId}/line-items/bulk-update-status', [\App\Http\Controllers\API\RiderController::class, 'bulkUpdateLineItemStatus']);
    Route::post('/orders/bulk-mark-prepared', [\App\Http\Controllers\CRM\OrderController::class, 'bulkMarkOrdersAsPrepared']);
    
    // Shopify Order Actions (Store Mode - uses token auth)
    Route::post('/orders/{id}/convert', [\App\Http\Controllers\CRM\OrderController::class, 'convertOrder']);
    Route::post('/orders/{id}/ignore', [\App\Http\Controllers\CRM\OrderController::class, 'ignoreOrder']);
    
    // Expense Management (Store Mode)
    Route::get('/expenses', [\App\Http\Controllers\API\RiderController::class, 'getExpenses']);
    // May-2026 — Qurbani Expenses screen summary card data.
    Route::get('/qurbani/expense-summary', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniExpenseSummary']);
    Route::get('/expenses/fund-transfers', [\App\Http\Controllers\API\RiderController::class, 'getFundTransfers']);
    // Last N movements IN and OUT of the expense account, with date/time/who (see the
    // controller note — fund-transfers only shows money ADDED this month).
    Route::get('/expenses/account-activity', [\App\Http\Controllers\API\RiderController::class, 'getAccountActivity']);
    Route::get('/expenses/payment-sources', [\App\Http\Controllers\API\RiderController::class, 'getPaymentSources']);
    Route::post('/expenses/set-default-account', [\App\Http\Controllers\API\RiderController::class, 'setBuDefaultExpenseAccount']); // ⭐ Set default expense account for a BU
    Route::get('/expenses/categories', [\App\Http\Controllers\API\RiderController::class, 'getExpenseCategoriesFromConfig']); // Fetch expense types from config (+ admin "bubbles")
    Route::post('/expenses/categories', [\App\Http\Controllers\FIN\ExpenseCategoryController::class, 'store']); // Create new expense category (reuses web controller)
    Route::post('/expenses/bubbles', [\App\Http\Controllers\API\RiderController::class, 'saveExpenseBubbles']); // Admin: choose/order the quick-select expense-type bubbles
    Route::post('/expenses/{id}/approve', [\App\Http\Controllers\API\RiderController::class, 'approveExpense']);
    Route::post('/expenses/{id}/reject', [\App\Http\Controllers\API\RiderController::class, 'rejectExpense']);
    Route::post('/expenses/{id}/settle', [\App\Http\Controllers\API\RiderController::class, 'settleExpense']);
    Route::delete('/expenses/{id}', [\App\Http\Controllers\API\RiderController::class, 'deleteExpense']); // L2 only
    Route::post('/expenses/{id}/delete', [\App\Http\Controllers\API\RiderController::class, 'deleteExpense']); // L2 only - POST alternative
    
    // NF Ledger (Store Mode - requires view_nf_ledger permission)
    Route::get('/nf-ledger/accounts', [\App\Http\Controllers\API\RiderController::class, 'getNFLedgerAccounts']);
    Route::get('/nf-ledger/accounts/{accountId}', [\App\Http\Controllers\API\RiderController::class, 'getNFLedgerDetails']);
    Route::post('/nf-ledger/accounts/{accountId}/petty-cash', [\App\Http\Controllers\API\RiderController::class, 'updatePettyCash']);
    Route::get('/nf-ledger/transfer-accounts', [\App\Http\Controllers\API\RiderController::class, 'getTransferAccounts']);
    // 💵 Tips held for staff (Sep-2026). Reading rides on view_nf_ledger; the
    // payout is gated to Shabib/Taimur inside the controller — the mobile client
    // can call directly, so UI hiding is not a gate.
    Route::get('/nf-ledger/tips', [\App\Http\Controllers\API\RiderController::class, 'getTipsFund']);
    Route::post('/nf-ledger/tips/payout', [\App\Http\Controllers\API\RiderController::class, 'payTipsOut']);
    Route::post('/nf-ledger/transfer', [\App\Http\Controllers\API\RiderController::class, 'processTransfer']);
    
    // Qurbani Mode
    Route::get('/qurbani/open-orders', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniOpenOrders']);
    Route::get('/qurbani/line-items', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniLineItems']);
    Route::get('/qurbani/field-options', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniFieldOptions']);
    // Booked summary + targets (soft-limit guidance on new-order form).
    Route::get('/qurbani/booked-stats', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniBookedStats']);
    Route::put('/orders/{id}/qurbani-details', [\App\Http\Controllers\API\RiderController::class, 'updateQurbaniDetails']);
    // May-2026: Per-line-item Qurbani status & rider assignment.
    // Bulk endpoints take an explicit `line_item_ids` array (no order_id in
    // the URL) so callers can never accidentally cascade to all items in
    // an order — that was the long-standing Mark-as-Prepared bug.
    Route::post('/qurbani/line-items/bulk-update-status', [\App\Http\Controllers\API\RiderController::class, 'bulkUpdateQurbaniItemStatus']);
    Route::post('/qurbani/line-items/bulk-assign-rider', [\App\Http\Controllers\API\RiderController::class, 'bulkAssignQurbaniRider']);
    Route::put('/qurbani/line-items/{lineItemId}/status', [\App\Http\Controllers\API\RiderController::class, 'updateQurbaniItemStatus']);
    Route::put('/qurbani/line-items/{lineItemId}/rider', [\App\Http\Controllers\API\RiderController::class, 'assignQurbaniRider']);
    // May-2026: Rider-mode Qurbani delivery feed + per-item mark-delivered.
    // The mark-delivered endpoints set qurbani_item_status='delivered' (NOT
    // order_status) so the rider can mark items spanning different
    // days/slots independently. Auto-promotes parent order_status only
    // when ALL items in the order are delivered, and that promotion's
    // ledger guard skips Qurbani via OrderModel::hasPreReceivedPayments().
    Route::get('/qurbani/my-deliveries', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniRiderDeliveries']);
    Route::post('/qurbani/line-items/bulk-mark-delivered', [\App\Http\Controllers\API\RiderController::class, 'bulkMarkQurbaniItemsDelivered']);
    Route::post('/qurbani/line-items/{lineItemId}/mark-delivered', [\App\Http\Controllers\API\RiderController::class, 'markQurbaniItemDelivered']);
    // Phase 6 (May-2026) — Mobile: queue + send one WhatsApp
    // qurbani_location template for a line-item's customer. Used by
    // the per-card "Request Location" button on QurbaniOpenOrdersScreen.
    // Bulk send is web-only on purpose — staff drives the mass campaign
    // from the desktop dashboard where the review drawer also lives.
    Route::post('/qurbani/loc-request/send-one', [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'sendOne']);
    // May-2026: Qurbani route / dispatch / ETA system (per-rider planner).
    // Mirrors the regular-orders updateDeliveryPriorities + calculate-etas +
    // lock/unlock flow but operates at the BUNDLE level (one stop per
    // smart-box bundle). Routes lock with mode='qurbani' so the same rider
    // can simultaneously have a regular-orders lock and a qurbani lock.
    Route::get('/qurbani/riders', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniRidersSummary']);
    Route::get('/qurbani/riders/{riderId}/route', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniRiderRoute']);
    Route::get('/qurbani/riders/{riderId}/dispatch-map', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniDispatchMap']);
    Route::post('/qurbani/riders/{riderId}/optimize-route', [\App\Http\Controllers\API\RiderController::class, 'optimizeQurbaniRoute']);
    Route::post('/qurbani/riders/{riderId}/save-route', [\App\Http\Controllers\API\RiderController::class, 'saveQurbaniRoute']);
    Route::post('/qurbani/riders/{riderId}/dispatch', [\App\Http\Controllers\API\RiderController::class, 'dispatchQurbaniRoute']);
    // May-2026 — handover support. Releases undelivered items from
    // an active dispatch back to "pending" so they can be
    // re-dispatched (same rider after a fix, or by a different
    // rider after the manager reassigns via the bulk-rider flow).
    // Accepts an optional batch_dispatched_at to target a specific
    // wave; omitting it cancels ALL active waves for the rider.
    Route::post('/qurbani/riders/{riderId}/cancel-dispatch', [\App\Http\Controllers\API\RiderController::class, 'cancelQurbaniDispatch']);
    // May-2026 — handover / change-rider for PENDING (not-yet-dispatched)
    // Qurbani OFD items. Source rider OR a manager may reassign a subset
    // of one rider's pending items to another rider. Frozen / dispatched
    // bundles are off-limits (use cancel-dispatch first to release them).
    // GET handover-riders returns the same Qurbani whitelist the manager
    // bulk picker uses, but without the `assign_riders` permission gate
    // so riders themselves can target other riders mid-route.
    Route::get('/qurbani/handover-riders', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniHandoverRiders']);
    Route::post('/qurbani/riders/{riderId}/handover', [\App\Http\Controllers\API\RiderController::class, 'handoverQurbaniItems']);
    // Phase G (May-2026) — post-dispatch reorder. Lets the manager (or
    // rider, on their own route) reshuffle ALREADY-dispatched bundles
    // and recompute ETAs against the rider's current GPS. ETAs are
    // only persisted when the new value differs from the saved value
    // by more than `eta_threshold_min` (default 30) to keep customer-
    // facing displays stable on minor reorders.
    Route::post('/qurbani/riders/{riderId}/edit-dispatched-route', [\App\Http\Controllers\API\RiderController::class, 'editDispatchedRoute']);
    // Phase 1 (May-2026) — Live ETA tracking toggle. Either the manager
    // (with access_qurbani_mode) or the rider themselves can flip it.
    Route::post('/qurbani/riders/{riderId}/live-tracking', [\App\Http\Controllers\API\RiderController::class, 'setQurbaniLiveTracking']);
    // Phase 2 (May-2026) — Qurbani order timeline (per line item).
    // Returns status + dispatch + current ETA + delay alert + today's
    // WhatsApp activity. Manager-only (access_qurbani_mode).
    Route::get('/qurbani/line-items/{lineItemId}/timeline', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniOrderTimeline']);
    Route::post('/qurbani/riders/{riderId}/lock-route', [\App\Http\Controllers\API\RiderController::class, 'lockQurbaniRoute']);
    Route::delete('/qurbani/riders/{riderId}/lock-route', [\App\Http\Controllers\API\RiderController::class, 'unlockQurbaniRoute']);
    // May-2026 (rev2) — manager-only WhatsApp delay-update flow.
    // GET feeds the "N stops · ETA slipped 30+ min" banner in the
    // mobile Qurbani Rider Route screen. POST resends the OFD template
    // with the new rounded-range ETA, gated by a 15-min/stop cooldown
    // enforced inside QurbaniWaAutoSender (so a runaway client can
    // never spam the customer side).
    Route::get('/qurbani/riders/{riderId}/delay-impacted',  [\App\Http\Controllers\API\RiderController::class, 'getQurbaniDelayImpacted']);
    Route::post('/qurbani/riders/{riderId}/send-delay-update', [\App\Http\Controllers\API\RiderController::class, 'sendQurbaniDelayUpdate']);
    Route::put('/orders/{orderId}/line-items/{lineItemId}/instructions', [\App\Http\Controllers\API\RiderController::class, 'updateLineItemInstructions']);
    // Barcode-qty: set an open order's line-item quantity from a scanned weight barcode.
    Route::put('/orders/{orderId}/line-items/{lineItemId}/quantity', [\App\Http\Controllers\API\RiderController::class, 'updateLineItemQuantity']);
    // 📦 Box scan: confirm a COUNTED product (frozen box) by its label. Changes no
    // quantity — see RiderController::confirmLineItemBoxScan.
    Route::post('/orders/{orderId}/line-items/{lineItemId}/box-scan', [\App\Http\Controllers\API\RiderController::class, 'confirmLineItemBoxScan']);
    // Barcode-qty: set MANY line-item quantities in one atomic request (manual "Save all").
    Route::put('/orders/{orderId}/line-items/quantities', [\App\Http\Controllers\API\RiderController::class, 'updateLineItemQuantitiesBatch']);
    // Aug-2026: mark an open order's line item free / charged from the app
    // (same control as the web Edit Invoice "Make Free" button). Gated by
    // create_orders, not the scan permission — see the controller docblock.
    Route::put('/orders/{orderId}/line-items/{lineItemId}/free', [\App\Http\Controllers\API\RiderController::class, 'updateLineItemFree']);
    // Line-item quick-note presets (chips next to the "Add note" box).
    // Shared team-wide list; same controller backs the web routes in
    // routes/web.php so there is one source of truth.
    Route::get('/line-item-quick-notes', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'index']);
    Route::post('/line-item-quick-notes', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'store']);
    Route::delete('/line-item-quick-notes/{id}', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'destroy']);
    Route::get('/orders/{id}/payments', [\App\Http\Controllers\API\RiderController::class, 'getOrderPayments']);
    Route::post('/orders/{id}/payments', [\App\Http\Controllers\API\RiderController::class, 'addOrderPayment']);
    // Bulk shop payment: split ONE online transfer across several selected shop
    // invoices (oldest-first, remainder on the newest). Shares the exact same
    // ShopBulkPaymentService as the web endpoint so the rule can't drift.
    // Literal path — kept above /orders/{id}/... so it is never captured by one.
    Route::post('/orders/bulk-shop-payments', [\App\Http\Controllers\API\ShopBulkPaymentController::class, 'store']);
    // Amend non-financial metadata (receiving bank, reference, notes, stamp
    // overrides) on an existing active payment. Cannot change amount /
    // method / date — those still require void-and-readd.
    Route::put('/orders/{orderId}/payments/{paymentId}', [\App\Http\Controllers\API\RiderController::class, 'updateOrderPayment']);
    // Void a previously-recorded payment (soft delete + ledger reversal).
    Route::delete('/orders/{orderId}/payments/{paymentId}', [\App\Http\Controllers\API\RiderController::class, 'deleteOrderPayment']);
    // Display-only edits of the invoice PAID stamp (mirrors the web
    // POST /orders/{id}/paid-stamp). Lets the team tweak sending bank /
    // stamp date / third-line mode after a payment was already recorded
    // without touching any payment or ledger row.
    Route::post('/orders/{id}/paid-stamp', [\App\Http\Controllers\API\RiderController::class, 'updatePaidStamp']);

    // Assets (Store Mode - requires view_assets permission)
    Route::get('/nf-ledger/assets', [\App\Http\Controllers\FIN\AssetController::class, 'apiIndex']);
    Route::get('/nf-ledger/assets/form-data', [\App\Http\Controllers\FIN\AssetController::class, 'apiFormData']);
    Route::get('/nf-ledger/assets/{id}', [\App\Http\Controllers\FIN\AssetController::class, 'apiShow']);
    Route::post('/nf-ledger/assets', [\App\Http\Controllers\FIN\AssetController::class, 'apiStore']);
    
    // Overall Ledger (Store Mode - requires view_nf_ledger permission)
    Route::get('/overall-ledger', [\App\Http\Controllers\API\RiderController::class, 'getOverallLedger']);
    
        // Store Attendance (Store Mode - requires view_store_attendance permission)
        Route::get('/store-attendance/daily', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceDaily']);
        Route::get('/store-attendance/monthly', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceMonthly']);
        Route::get('/store-attendance/employee-details', [\App\Http\Controllers\API\RiderController::class, 'getStoreAttendanceEmployeeDetails']);
        Route::post('/store-attendance/update-meter-values', [\App\Http\Controllers\API\RiderController::class, 'updateMeterValues']);
        // Rider bypasses (mobile manager; view_store_attendance-gated wrappers of the web endpoints)
        Route::post('/store-attendance/checkout-unlock', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceCheckoutUnlock']);
        Route::post('/store-attendance/undo-checkout', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceUndoCheckout']); // back on duty after an extra order
        Route::post('/store-attendance/home-unlock', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceHomeUnlock']);
        Route::post('/store-attendance/home-enter-meter', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceHomeEnterMeter']);
        Route::post('/store-attendance/checkin-unlock', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceCheckinUnlock']); // U5 morning-lock valve
        // Live "rider stuck at checkout" alerts (mobile manager banner) + dismiss
        Route::get('/store-attendance/checkout-stuck-alerts', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceCheckoutStuckAlerts']);
        Route::post('/store-attendance/checkout-stuck-dismiss', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceDismissCheckoutStuck']);

        // ⭐ Road Distance Calculation (on-demand) - uses OpenRouteService API
        Route::get('/store-attendance/calculate-road-distance', [\App\Http\Controllers\API\RiderController::class, 'calculateRoadDistance']);
        Route::get('/store-attendance/gps-audit', [\App\Http\Controllers\API\RiderController::class, 'storeAttendanceGpsAudit']); // ⛽/📡 tick detail sheets
        
        // ⭐ Employee Profiles (Store Mode - for salary management)
        Route::get('/store-salary/employees', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getData']);
        
        // ⭐ Loan Management (Store Mode - same as web)
        Route::post('/store-salary/loans', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'store']);
        Route::put('/store-salary/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'update']);
        Route::post('/store-salary/loans/{id}/cancel', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'cancel']);
        Route::get('/store-salary/loans/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'show']);
        
        // ⭐ Salary Advance Settlement (Store Mode)
        Route::post('/store-salary/advances/{id}/settle', [\App\Http\Controllers\API\RiderController::class, 'settleSalaryAdvance']);
        
        // ⭐ Get employee details with loans and advances (Store Mode)
        Route::get('/store-salary/employee/{userId}/details', [\App\Http\Controllers\API\RiderController::class, 'getEmployeeSalaryDetails']);
        
        // ⭐ Create salary advance for employee (Store Mode)
        Route::post('/requests/create-salary-advance', [\App\Http\Controllers\API\RiderController::class, 'createSalaryAdvance']);
        
        // ⭐ Get available disbursement accounts (Store Mode)
        Route::get('/store-salary/disbursement-accounts', [\App\Http\Controllers\API\RiderController::class, 'getDisbursementAccounts']);
        
        // ⭐ Salary Slip Management (Store Mode)
        Route::get('/store-salary/salary-slips', [\App\Http\Controllers\API\RiderController::class, 'getSalarySlips']);
        Route::get('/store-salary/salary-slips/calculate', [\App\Http\Controllers\API\RiderController::class, 'calculateSalary']);
        Route::post('/store-salary/salary-slips', [\App\Http\Controllers\API\RiderController::class, 'createSalarySlip']);
        Route::get('/store-salary/salary-slips/{id}', [\App\Http\Controllers\API\RiderController::class, 'getStoreSalarySlipDetails']);
        
        // ⭐ ETA to destination (Google Maps with fallback to OpenRouteService)
        Route::get('/eta-to-destination', [\App\Http\Controllers\API\RiderController::class, 'getEtaToDestination']);
        Route::get('/api-usage-stats', [\App\Http\Controllers\API\RiderController::class, 'getApiUsageStats']);
        
        // ⭐ Calculate delivery ETAs for rider's out_for_delivery orders (manual trigger)
        Route::post('/{riderId}/calculate-delivery-etas', [\App\Http\Controllers\API\RiderController::class, 'calculateDeliveryEtas']);
        Route::post('/{riderId}/cancel-dispatch', [\App\Http\Controllers\API\RiderController::class, 'cancelDispatch']); // ⭐ Clear ETAs to merge dispatch waves
        Route::get('/{riderId}/dispatch-preview', [\App\Http\Controllers\API\RiderController::class, 'dispatchPreview']); // 🧭 Sep-2026: where is he + ping age, before the store dispatches
        Route::post('/{riderId}/optimize-route', [\App\Http\Controllers\API\RiderController::class, 'optimizeRoute']);
        Route::get('/{riderId}/route-lock', [\App\Http\Controllers\API\RiderController::class, 'getRouteLock']); // ⭐ Read-only: who set this route (rider info prompt)
        Route::post('/{riderId}/route-lock', [\App\Http\Controllers\API\RiderController::class, 'lockRoute']);
        Route::delete('/{riderId}/route-lock', [\App\Http\Controllers\API\RiderController::class, 'unlockRoute']);
        
        // ⭐ LOCATION TRACKING: Heartbeat from mobile app (every 5 minutes when checked in)
        Route::post('/location-heartbeat', [\App\Http\Controllers\API\RiderController::class, 'locationHeartbeat']);
        
        // ⭐ LOCATION TRACKING: Log location failures (helps diagnose GPS gaps)
        Route::post('/location-failure', [\App\Http\Controllers\API\RiderController::class, 'logLocationFailure']);
        
        // ⭐ LOCATION TRACKING: Get active riders for map (mobile + web)
        Route::get('/active-riders-map', [\App\Http\Controllers\API\RiderController::class, 'getActiveRidersForMap']);
        Route::get('/location-heartbeat/active-riders', [\App\Http\Controllers\API\RiderController::class, 'getActiveRidersForMap']);
        
        // ⭐ LOCATION TRACKING: Get detailed map data for a specific rider (mobile + web)
        Route::get('/rider-map/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderMapData']);
        Route::get('/location-heartbeat/rider-map/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderMapData']);
        
        // ⭐ LOCATION TRACKING: Get rider location history (last N unique locations)
        Route::get('/rider-map/{riderId}/location-history', [\App\Http\Controllers\API\RiderController::class, 'getRiderLocationHistory']);
        
        // ⭐ LOCATION TRACKING: Get GPS trail segment between two deliveries
        Route::get('/rider-map/{riderId}/trail-segment', [\App\Http\Controllers\API\RiderController::class, 'getTrailSegment']);
        
        // ⭐ DELIVERY JOURNEY: Analyze rider journey for a delivered order
        Route::get('/delivery-journey/{orderId}', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryJourney']);
        
        // ⭐ LOCATION TRACKING: Get all open orders for map view
        Route::get('/all-open-orders-map', [\App\Http\Controllers\API\RiderController::class, 'getAllOpenOrdersForMap']);
        
        // ⭐ LOCATION TRACKING: Get delivery history for a date
        Route::get('/delivery-history', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryHistory']);
        
        // ⭐ HISTORY: Get riders list and individual rider history
        Route::get('/riders-for-history', [\App\Http\Controllers\API\RiderController::class, 'getRidersForHistory']);
        Route::get('/rider-history/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderDeliveryHistory']);
    }); // Close rider prefix group
    
    // Store Mode - Requests (uses same controller as web for consistency, under /api prefix)
    Route::post('/requests/store', [\App\Http\Controllers\Request\RequestController::class, 'store']);
    // Lightweight feed for the in-app "new leave added" banner (count + newest id).
    Route::get('/requests/leave-alert', [\App\Http\Controllers\Request\RequestController::class, 'leaveAlertSummary']);
    Route::post('/requests/{id}/approve', [\App\Http\Controllers\Request\RequestApprovalController::class, 'approve']);
    Route::post('/requests/{id}/reject', [\App\Http\Controllers\Request\RequestApprovalController::class, 'reject']);
    
    // Ledger Approvals (for mobile app)
    Route::post('/ledger/{id}/approve', [\App\Http\Controllers\FIN\LedgerController::class, 'approve']);
    Route::post('/ledger/{id}/approve-l1-only', [\App\Http\Controllers\FIN\LedgerController::class, 'approveAtL1Only']);
    Route::post('/ledger/{id}/reject', [\App\Http\Controllers\FIN\LedgerController::class, 'reject']);
    Route::post('/ledger/adjustments/{id}/approve', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'approve']);
    Route::post('/ledger/adjustments/{id}/reject', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'reject']);
    
    // Vendor Management (Store Mode - uses token auth, but NOT under /rider prefix)
    Route::prefix('vendors')->group(function () {
        Route::get('/', [\App\Http\Controllers\FIN\VendorController::class, 'index']);
        Route::get('/monthly-summary', [\App\Http\Controllers\FIN\VendorController::class, 'monthlySummary']);
        // Vendor transaction report (mirrors web fin.vendors.report). MUST stay before /{id}
        // so this static path isn't captured by the {id} wildcard. Reuses the same
        // VendorController@getReport used by the web report modal — identical KPIs.
        Route::get('/report', [\App\Http\Controllers\FIN\VendorController::class, 'getReport']);

        // 🧾 ⚠⚠ MUST STAY ABOVE `GET /{id}`. Laravel matches in registration order, so
        //    below it these would resolve as a vendor whose id is "receipt-drafts".
        Route::get('/receipt-drafts', [\App\Http\Controllers\Khaas\ReceiptController::class, 'drafts']);
        Route::get('/receipt-drafts/{id}', [\App\Http\Controllers\Khaas\ReceiptController::class, 'show']);

        Route::get('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'show']);
        Route::post('/{id}/purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordPurchase']);
        Route::post('/{id}/payment', [\App\Http\Controllers\FIN\VendorController::class, 'recordPayment']);
        Route::post('/{id}/weighted-purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordWeightedPurchase']);
        // 🔀 Sep-27: switch a vendor between by-total and by-weight (Frozen staff on Frozen vendors).
        Route::post('/{id}/purchase-method', [\App\Http\Controllers\FIN\VendorController::class, 'setPurchaseMethod']);

        // 🧾 Sep-2026 RECEIPT CAPTURE — photo in, a card to confirm out.
        // ⚠ None of these write money. The card becomes a purchase only through
        //   /weighted-purchase above, which stays the single writer.
        // ⚠⚠ The two GET routes live ABOVE `GET /{id}` (see below) — registered here
        //    they would be swallowed by it and resolve as vendor id "receipt-drafts".
        Route::post('/{id}/receipt/extract', [\App\Http\Controllers\Khaas\ReceiptController::class, 'extract']);
        // ⭐⭐ A card becomes a purchase HERE, not by posting straight at
        //    /weighted-purchase. This claims the draft under a row lock first, so a
        //    retry after a timeout cannot book the same bill twice — which is exactly
        //    what the screen promises the person pressing Record.
        Route::post('/{id}/receipt/record', [\App\Http\Controllers\Khaas\ReceiptController::class, 'record']);
        Route::post('/receipt-drafts/{id}/discard', [\App\Http\Controllers\Khaas\ReceiptController::class, 'discard']);
        Route::post('/transaction/{id}/delete', [\App\Http\Controllers\FIN\VendorController::class, 'deleteTransaction']);
        Route::post('/transaction/{id}/update', [\App\Http\Controllers\FIN\VendorController::class, 'updateTransaction']);
        
        // Vendor Products (for weight-based vendors)
        Route::get('/{id}/products/list', [\App\Http\Controllers\FIN\VendorProductController::class, 'list']);
        Route::post('/{id}/products', [\App\Http\Controllers\FIN\VendorProductController::class, 'store']);
        Route::put('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'update']);
        // Sep-26: the phone's Products screen — same two doors the web page has always had.
        Route::post('/{vendorId}/products/{productId}/toggle', [\App\Http\Controllers\FIN\VendorProductController::class, 'toggleStatus']);
        Route::post('/{vendorId}/products/{productId}/set-default', [\App\Http\Controllers\FIN\VendorProductController::class, 'setAsDefault']);
        Route::delete('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'destroy']);
    });

    // Ledger transaction details (used by mobile vendor view - mirrors web route)
    Route::get('/finance/ledger/transaction/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'getTransactionDetails']);
    
    // Shipping price endpoint (for mobile order creation)
    Route::get('/shipping/price', [\App\Http\Controllers\ShippingController::class, 'getPrice']);
    
    // Payment source accounts (for vendor payments in mobile)
    Route::get('/finance/accounts/payment-sources', [\App\Http\Controllers\FIN\AccountController::class, 'getPaymentSources']);
    
    // ⭐ Business Units (for dropdowns in mobile/web)
    Route::get('/business-units', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'apiList']);

    // ⭐ Online Receiving Accounts (for dropdowns in approval flow). Each row
    // carries its computed current `balance` so pickers can show how much sits
    // in each bank (opening balance + net tagged movement).
    Route::get('/online-receiving-accounts', function () {
        $accounts = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance']);
        $byBank = app(\App\Services\FIN\BankBalanceService::class)->balancesByBank();
        $data = $accounts->map(function ($acc) use ($byBank) {
            return [
                'id' => $acc->id,
                'name' => $acc->name,
                'short_code' => $acc->short_code,
                'color_hex' => $acc->color_hex,
                'balance' => $byBank[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ];
        });
        return response()->json(['success' => true, 'data' => $data]);
    });

    // Quick-create endpoint for the mobile Qurbani Pay modal's "+ Add new"
    // bank flow. Re-uses the web OnlineReceivingAccountController so the
    // validation + defaulting logic (sort_order bump, is_active=true,
    // default colour) stays in a single place and the two call sites
    // cannot drift.
    Route::post('/online-receiving-accounts', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'store']);
    
    // ============================
    // Products (Mobile Store Mode)
    // ============================
    Route::prefix('products')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\ProductController::class, 'index']);
        Route::get('/search', [\App\Http\Controllers\CRM\ProductController::class, 'search']);
        Route::get('/dropdown-options', [\App\Http\Controllers\CRM\ProductController::class, 'getDropdownOptions']);
        Route::get('/{id}/history', [\App\Http\Controllers\CRM\ProductController::class, 'getHistory']); // Product change history
        Route::get('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'destroy']);
        Route::post('/', [\App\Http\Controllers\CRM\ProductController::class, 'store']);
        Route::post('/bulk-adjust-prices/preview', [\App\Http\Controllers\CRM\ProductController::class, 'previewBulkAdjustPrices']);
        Route::post('/bulk-adjust-prices', [\App\Http\Controllers\CRM\ProductController::class, 'bulkAdjustPrices']);
        Route::post('/bulk-set-weight-factor', [\App\Http\Controllers\CRM\ProductController::class, 'bulkSetWeightFactor']);
        Route::get('/attributes/list', [\App\Http\Controllers\CRM\ProductController::class, 'attributes']);
        Route::post('/attributes/apply', [\App\Http\Controllers\CRM\ProductController::class, 'applyAttributeRules']);
    });
    
    // ============================
    // Warehouse Inventory (Khaas Mode)
    // ============================
    Route::prefix('warehouse')->group(function () {
        Route::get('/inventory', [\App\Http\Controllers\CRM\WarehouseController::class, 'getInventory']);
        Route::post('/stock', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStock']);
        Route::post('/transfer', [\App\Http\Controllers\CRM\WarehouseController::class, 'initiateTransfer']);
        Route::get('/transfers/pending', [\App\Http\Controllers\CRM\WarehouseController::class, 'getPendingTransfers']);
        Route::get('/transfers/history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getTransferHistory']);
        Route::post('/transfer/{id}/approve', [\App\Http\Controllers\CRM\WarehouseController::class, 'approveTransfer']);
        Route::post('/transfer/{id}/reject', [\App\Http\Controllers\CRM\WarehouseController::class, 'rejectTransfer']);
        // Aug-2026: options for the "Counted by" picker shown when accepting a
        // transfer. Same roster as the Attendance page — deliberately NOT
        // /rider/users/active, which is every active account and excludes self.
        Route::get('/counted-by-users', [\App\Http\Controllers\CRM\WarehouseController::class, 'getCountedByUsers']);
        Route::get('/product-history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getProductHistory']);

        // ⭐ Aug-2026 TRANSFER REQUESTS — "please send me stock", the step BEFORE a
        // transfer. A request moves NO inventory; only /accept does, and it runs the
        // same initiation code a direct /transfer does. Note the singular
        // 'transfer-request' prefix, distinct from the plural 'transfers' above:
        // requests and transfers are different objects and must not be confused.
        Route::get('/transfer-requests/pending', [\App\Http\Controllers\CRM\WarehouseController::class, 'getPendingTransferRequests']);
        Route::post('/transfer-request', [\App\Http\Controllers\CRM\WarehouseController::class, 'createTransferRequest']);
        Route::post('/transfer-request/{id}/accept', [\App\Http\Controllers\CRM\WarehouseController::class, 'acceptTransferRequest']);
        Route::post('/transfer-request/{id}/decline', [\App\Http\Controllers\CRM\WarehouseController::class, 'declineTransferRequest']);
        Route::post('/transfer-request/{id}/cancel', [\App\Http\Controllers\CRM\WarehouseController::class, 'cancelTransferRequest']);
        
        // ⭐ Batch Production Tracking
        Route::get('/batch/active', [\App\Http\Controllers\CRM\WarehouseController::class, 'getActiveBatches']);
        Route::post('/batch/start', [\App\Http\Controllers\CRM\WarehouseController::class, 'startBatch']);
        Route::post('/batch/{id}/end', [\App\Http\Controllers\CRM\WarehouseController::class, 'endBatch']);
        Route::post('/batch/{id}/cancel', [\App\Http\Controllers\CRM\WarehouseController::class, 'cancelBatch']);
        Route::get('/batch/product/{productId}/history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getBatchHistory']);
        
        // ⭐ Inventory & Sales Report
        Route::get('/inventory-report', [\App\Http\Controllers\CRM\WarehouseController::class, 'inventoryReport']);
        Route::get('/inventory-report/daily', [\App\Http\Controllers\CRM\WarehouseController::class, 'dailyInventoryReport']);
        
        // ⭐ Storage Feature (NF Meat → Khaas Warehouse)
        Route::get('/storage/inventory', [\App\Http\Controllers\CRM\WarehouseController::class, 'getStorageInventory']);
        Route::get('/storage/available-products', [\App\Http\Controllers\CRM\WarehouseController::class, 'getAvailableStorageProducts']);
        Route::post('/storage/config', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStorageConfig']);
        Route::post('/storage/order', [\App\Http\Controllers\CRM\WarehouseController::class, 'placeStorageOrder']);
        Route::post('/storage/order/{id}/receive', [\App\Http\Controllers\CRM\WarehouseController::class, 'receiveStorageOrder']);
        Route::post('/storage/use', [\App\Http\Controllers\CRM\WarehouseController::class, 'useStorageItem']);
        Route::post('/storage/adjust', [\App\Http\Controllers\CRM\WarehouseController::class, 'adjustStorageItem']);
        Route::get('/storage/product/{productId}/monthly', [\App\Http\Controllers\CRM\WarehouseController::class, 'getStorageMonthlyBreakdown']);
        Route::post('/storage/settings', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateStorageSettings']);
        
        Route::get('/products/{productId}/store-log', [\App\Http\Controllers\KhaasController::class, 'getStoreInventoryLog']);
        Route::get('/products/{productId}/warehouse-log', [\App\Http\Controllers\KhaasController::class, 'getWarehouseInventoryLog']);
        // 🛒 Sep-26: the orders behind a product's open-order number — the same method the web popup uses.
        Route::get('/products/{productId}/pending-orders', [\App\Http\Controllers\KhaasController::class, 'pendingOrdersBreakdown']);
        Route::get('/sales-report', [\App\Http\Controllers\KhaasController::class, 'salesReportApi']);
        Route::get('/sales-report/daily', [\App\Http\Controllers\KhaasController::class, 'salesReportDaily']);
        Route::get('/sales-report/product/{productId}/daily', [\App\Http\Controllers\KhaasController::class, 'productDailyBreakdown']);

        // Production Demand
        Route::get('/demand/products', [\App\Http\Controllers\CRM\WarehouseController::class, 'getDemandProducts']);
        Route::get('/demand/list', [\App\Http\Controllers\CRM\WarehouseController::class, 'getDemands']);
        Route::get('/demand/history', [\App\Http\Controllers\CRM\WarehouseController::class, 'getDemandHistory']);
        Route::post('/demand/create', [\App\Http\Controllers\CRM\WarehouseController::class, 'createDemand']);
        Route::post('/demand/{id}/update', [\App\Http\Controllers\CRM\WarehouseController::class, 'updateDemand']);
        Route::post('/demand/{id}/accept', [\App\Http\Controllers\CRM\WarehouseController::class, 'acceptDemand']);
        Route::post('/demand/{id}/cancel', [\App\Http\Controllers\CRM\WarehouseController::class, 'cancelDemand']);
        Route::get('/recipes', [\App\Http\Controllers\CRM\WarehouseController::class, 'getProductRecipes']);
        Route::post('/recipes/save', [\App\Http\Controllers\CRM\WarehouseController::class, 'saveProductRecipe']);
        Route::post('/recipes/delete', [\App\Http\Controllers\CRM\WarehouseController::class, 'deleteProductRecipe']);
        Route::get('/custom-materials', [\App\Http\Controllers\CRM\WarehouseController::class, 'getCustomMaterials']);
        Route::post('/custom-materials', [\App\Http\Controllers\CRM\WarehouseController::class, 'saveCustomMaterial']);

        // ❄🧾 Sep-2026 RECIPES + INGREDIENT COSTING.
        // Deliberately NOT folded into the legacy /recipes endpoints above: those keep
        // driving the storage deduction when a production plan is accepted, and must
        // not change shape under an installed APK. These are the new, quantified ones.
        // ⚠ This group has no permission middleware (a known, accepted gap), so every
        //   write below checks its own permission inside the controller.
        Route::get('/ingredients', [\App\Http\Controllers\Khaas\RecipeController::class, 'ingredients']);
        Route::post('/ingredients', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveIngredient']);
        Route::post('/ingredients/{id}/deactivate', [\App\Http\Controllers\Khaas\RecipeController::class, 'deactivateIngredient']);
        // ⭐ Sep-26: one unit per ingredient, changed everywhere at once (recipes + tagged products).
        Route::get('/ingredients/{id}/unit-impact', [\App\Http\Controllers\Khaas\RecipeController::class, 'unitImpact']);
        Route::post('/ingredients/{id}/change-unit', [\App\Http\Controllers\Khaas\RecipeController::class, 'changeUnit']);
        Route::get('/ingredients/month', [\App\Http\Controllers\Khaas\RecipeController::class, 'ingredientMonth']);
        Route::post('/ingredients/opening', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveOpening']);
        // ❄🧂 Sep-30: ingredient stock (start + bought − used = left), hide/unhide, replace.
        Route::get('/ingredients/stock', [\App\Http\Controllers\Khaas\RecipeController::class, 'stockSheet']);
        Route::post('/ingredients/stock', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveStock']);
        Route::get('/ingredients/{id}/stock-history', [\App\Http\Controllers\Khaas\RecipeController::class, 'stockHistory']);
        Route::post('/ingredients/{id}/activate', [\App\Http\Controllers\Khaas\RecipeController::class, 'activateIngredient']);
        Route::get('/ingredients/{id}/replace-impact', [\App\Http\Controllers\Khaas\RecipeController::class, 'replaceImpact']);
        Route::post('/ingredients/{id}/replace', [\App\Http\Controllers\Khaas\RecipeController::class, 'replaceIngredient']);
        Route::get('/recipe', [\App\Http\Controllers\Khaas\RecipeController::class, 'show']);
        Route::post('/recipe', [\App\Http\Controllers\Khaas\RecipeController::class, 'save']);
        Route::get('/recipe/coverage', [\App\Http\Controllers\Khaas\RecipeController::class, 'coverage']);
        Route::get('/consumption', [\App\Http\Controllers\Khaas\RecipeController::class, 'consumption']);
        Route::get('/product-cost', [\App\Http\Controllers\Khaas\RecipeController::class, 'productCost']);
        Route::get('/product-costs', [\App\Http\Controllers\Khaas\RecipeController::class, 'productCosts']);
        Route::post('/consumption/reapply', [\App\Http\Controllers\Khaas\RecipeController::class, 'reapply']);

        Route::get('/khaas-badges', [\App\Http\Controllers\CRM\WarehouseController::class, 'getKhaasBadges']);

        // 📊 Sep-2026 Month Review — the SAME FrozenMonthService that builds
        // /warehouse/inventory-report above, so the phone and the web page can
        // never quote different production numbers for a month.
        Route::get('/month-review', [\App\Http\Controllers\KhaasController::class, 'monthReviewApi']);
    });

    // ============================
    // 📦 Storage (Supplies) — packaging bought in bulk, charged to expenses one packet
    // at a time as it is used. Same controller methods are mounted on /supplies/* in
    // routes/web.php, so the phone and the web page can never disagree.
    // Gated INSIDE the controller by access_supplies_storage / manage_supplies_storage.
    // ============================
    Route::prefix('supplies')->group(function () {
        Route::get('/stock', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getStock']);
        Route::get('/products', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getProducts']);
        Route::get('/history', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getHistory']);
        Route::get('/my-takeouts', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getMyTakeouts']);
        Route::get('/alerts', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'alerts']);
        Route::get('/{productId}/batches', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getBatches']);
        Route::get('/{productId}/packets', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getPackets']);
        Route::post('/products', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'saveProduct']);
        Route::post('/products/{id}', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'saveProduct']);
        Route::post('/batches', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'bookBatch']);
        Route::post('/batches/{batchId}/void', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'voidBatch']);
        Route::post('/batches/{batchId}/preview-correction', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'previewCorrection']);
        Route::post('/batches/{batchId}/correct-price', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'correctPrice']);
        Route::post('/{productId}/count', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'recordCount']);
        Route::post('/decode', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'decodeBarcode']);
        Route::post('/resolve-scan', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'resolveScan']);
        Route::post('/take-out', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'takeOut']);
        Route::post('/take-out/{takeoutId}/undo', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'undoTakeout']);
        // ⭐ Round 3 — the same four the web mount carries, so the phone can do everything.
        Route::post('/take-out/quote', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'quoteTakeout']);
        Route::post('/take-out/{takeoutId}/delete', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'deleteTakeout']);
        Route::post('/take-out/{takeoutId}/preview-edit', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'previewTakeoutEdit']);
        Route::post('/take-out/{takeoutId}/edit', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'editTakeout']);
        Route::post('/approval-switch', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'setApprovalSwitch']);
    });

    // ============================
    // Overnight Storage (NF store chiller/freezer tracker — standalone, no inventory impact)
    // Same controller methods are mounted on /overnight/* in routes/web.php.
    // ============================
    Route::prefix('overnight')->group(function () {
        Route::get('/items', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getItems']);
        Route::get('/products', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getProductsMap']);
        Route::post('/items', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'addItems']);
        Route::post('/move', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'moveItems']);
        Route::post('/take-out', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'takeOutItems']);
        Route::post('/verify', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'verifyItems']);
        Route::get('/history', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getHistory']);
        Route::get('/daily-summary', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getDailySummary']);
    });

    // ============================
    // RETURNS — store side (Sep-2026). The manager takes the return on the web
    // (money + which shelf); this is the "scan it back onto the shelf" half.
    // Gated on the mobile permission `scan_returns`, checked per call so the
    // banner follows whoever is on shift rather than a login-time snapshot.
    // ============================
    Route::prefix('store/returns')->group(function () {
        Route::get('/pending', [\App\Http\Controllers\API\ReturnsAPIController::class, 'pending']);
        Route::get('/{returnId}', [\App\Http\Controllers\API\ReturnsAPIController::class, 'detail']);
        Route::post('/{returnId}/scan', [\App\Http\Controllers\API\ReturnsAPIController::class, 'scan']);
        Route::post('/{returnId}/manual', [\App\Http\Controllers\API\ReturnsAPIController::class, 'manual']);
        Route::post('/{returnId}/complete', [\App\Http\Controllers\API\ReturnsAPIController::class, 'complete']);
    });

    // ============================
    // Customers (Mobile Store Mode)
    // ============================
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CustomerController::class, 'index']);
        Route::get('/filter', [\App\Http\Controllers\CRM\CustomerController::class, 'filter']);
        Route::get('/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search']);
        // ⭐ NEW: Customer creation for mobile (must be before {id} routes)
        Route::post('/', [\App\Http\Controllers\CRM\CustomerController::class, 'store']); // Create new customer
        Route::post('/check-phone', [\App\Http\Controllers\CRM\CustomerController::class, 'checkPhone']); // Check phone duplicate
        // History order details route (must be before {id} to avoid conflict)
        Route::get('/history-order/{historyOrderId}', [\App\Http\Controllers\CRM\CustomerController::class, 'historyOrderDetails']);
        Route::get('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'show']);
        Route::get('/{id}/orders', [\App\Http\Controllers\CRM\CustomerController::class, 'orders']);
        Route::put('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'update']);
        Route::post('/{id}/notes', [\App\Http\Controllers\CRM\CustomerController::class, 'addNote']);
        Route::post('/{id}/set-verified-location', [\App\Http\Controllers\CRM\CustomerController::class, 'setVerifiedLocation']);
        // Aug-2026 — pin history for the mobile sheet. Same controller method as
        // the web route so both surfaces read one wording (PinHistoryService).
        Route::get('/{id}/location-history', [\App\Http\Controllers\CRM\CustomerController::class, 'locationHistory']);
        Route::post('/{id}/geocode', [\App\Http\Controllers\CRM\CustomerController::class, 'geocode']);
        Route::post('/{id}/geocode-single', [\App\Http\Controllers\CRM\CustomerController::class, 'geocodeSingle']);
    });
    
    // ============================
    // WhatsApp Messaging (Store Mode)
    // ============================
    Route::prefix('whatsapp')->group(function () {
        Route::get('/conversations', [WhatsAppController::class, 'getConversations']);
        Route::get('/conversations/{id}', [WhatsAppController::class, 'getMessages']);
        Route::post('/conversations/{id}/send', [WhatsAppController::class, 'sendMessage']);
        Route::post('/conversations/{id}/send-voice', [WhatsAppController::class, 'sendVoiceNote']);
        // NF Messages chat enhancements (additive; primary app never calls these)
        Route::post('/conversations/{id}/send-image', [WhatsAppController::class, 'sendImageMessage']);
        Route::get('/conversations/{id}/search', [WhatsAppController::class, 'searchMessages']);
        Route::post('/conversations/{id}/mark-read', [WhatsAppController::class, 'markRead']);
        Route::post('/conversations/{id}/mark-unread', [WhatsAppController::class, 'markUnread']);
        // Apr-2026: Bulk Mark-All-Read — super-reader only (Taimur role).
        Route::post('/mark-all-read', [WhatsAppController::class, 'markAllRead']);
        // Labels (Phase 1)
        Route::get('/labels', [WhatsAppController::class, 'getLabels']);
        Route::post('/labels', [WhatsAppController::class, 'createLabel']);
        Route::put('/labels/{id}', [WhatsAppController::class, 'updateLabel']);
        Route::delete('/labels/{id}', [WhatsAppController::class, 'deleteLabel']);
        Route::post('/conversations/{id}/labels', [WhatsAppController::class, 'applyLabel']);
        Route::delete('/conversations/{id}/labels/{labelId}', [WhatsAppController::class, 'removeLabel']);
        // Labels — Phase 2 (user mentions)
        Route::get('/label-users',     [WhatsAppController::class, 'getLabelUsers']);
        Route::get('/mentions-count',  [WhatsAppController::class, 'getMentionsCount']);
        Route::post('/send-template', [WhatsAppController::class, 'sendTemplate']);
        // Marketing-template dedup pre-flight + pinned indicator endpoints.
        // See add_marketing_dedup_apr2026.sql migration.
        Route::post('/template-recent-send-check', [WhatsAppController::class, 'templateRecentSendCheck']);
        Route::get('/conversations/{id}/recent-marketing-templates', [WhatsAppController::class, 'recentMarketingTemplates']);
        Route::get('/unread-count', [WhatsAppController::class, 'getUnreadCount']);
        Route::get('/templates', [WhatsAppController::class, 'getTemplates']);
        Route::get('/cost-summary', [WhatsAppController::class, 'getCostSummary']);
        Route::post('/send-to-customer', [WhatsAppController::class, 'sendToPhone']);
        Route::post('/register-device', [WhatsAppController::class, 'registerDevice']);
        Route::post('/unregister-device', [WhatsAppController::class, 'unregisterDevice']);
        Route::post('/test-notification', [WhatsAppController::class, 'testNotification']);
        Route::get('/customer-orders/{customerId}', [WhatsAppController::class, 'getCustomerOrders']);
        Route::get('/invoice-image/{orderId}', [WhatsAppController::class, 'getInvoiceImageUrl']);
        Route::get('/invoice-send-plan/{orderId}', [WhatsAppController::class, 'invoiceSendPlan']);
        Route::post('/upload-invoice-image', [WhatsAppController::class, 'uploadInvoiceImage']);
        Route::post('/send-invoice', [WhatsAppController::class, 'sendInvoice']);

        // Qurbani tab settings (mirror web).
        Route::get('/qurbani-settings', [WhatsAppController::class, 'getQurbaniSettings']);
        Route::post('/qurbani-settings', [WhatsAppController::class, 'updateQurbaniSettings']);
        Route::post('/qurbani-rescan', [WhatsAppController::class, 'rescanQurbani']);
    });

    // WhatsApp per-customer (nested under customers)
    Route::get('/customers/{id}/whatsapp-messages', [WhatsAppController::class, 'getCustomerMessages']);
    Route::post('/customers/{id}/whatsapp-send', [WhatsAppController::class, 'sendToCustomer']);

    // ============================
    // NF Assistant (AI chat inside NF Messages)
    // ============================
    // Sanctum-authed by the enclosing group; each method ALSO requires the
    // `use_ai_assistant` mobile permission (launch = the CEO only).
    //
    // /message is the agent turn — the LLM can only PREPARE a draft.
    // /confirm is the only path that writes money, and it replays the draft
    // through the existing endpoints (RequestController@store,
    // VendorController@recordPayment) so every approval/guard still applies.
    // See NF-ASSISTANT-AGENT-PLAN-JUL2026.md.
    Route::prefix('assistant')->group(function () {
        Route::post('/message', [\App\Http\Controllers\API\AssistantController::class, 'message']);
        Route::post('/confirm/{draft}', [\App\Http\Controllers\API\AssistantController::class, 'confirm']);
        Route::post('/cancel/{draft}', [\App\Http\Controllers\API\AssistantController::class, 'cancel']);
        // One-tap bank picker on the card (no model call, pure DB write).
        Route::post('/draft/{draft}/choose', [\App\Http\Controllers\API\AssistantController::class, 'choose']);
        // Drop working context (visible thread stays). No model call.
        Route::post('/new-topic', [\App\Http\Controllers\API\AssistantController::class, 'newTopic']);
        Route::get('/history', [\App\Http\Controllers\API\AssistantController::class, 'history_endpoint']);

        // Settings sheet (defaults, SMS sender→bank map, trusted forwarders).
        // No model call — pure config the user edits. See
        // NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026.md Phase 1.
        Route::get('/settings', [\App\Http\Controllers\API\AssistantSettingsController::class, 'index']);
        Route::put('/settings/default', [\App\Http\Controllers\API\AssistantSettingsController::class, 'setDefault']);
        Route::post('/settings/sender', [\App\Http\Controllers\API\AssistantSettingsController::class, 'saveSender']);
        Route::delete('/settings/sender/{id}', [\App\Http\Controllers\API\AssistantSettingsController::class, 'deleteSender']);
        Route::post('/settings/forwarder', [\App\Http\Controllers\API\AssistantSettingsController::class, 'saveForwarder']);
        Route::delete('/settings/forwarder/{id}', [\App\Http\Controllers\API\AssistantSettingsController::class, 'deleteForwarder']);

        // Money workspace: status-strip counts + the money inbox. Pure DB, no
        // LLM. See NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026.md Phase 2.
        Route::get('/workspace/summary', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'summary']);
        Route::get('/workspace/inbox', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'inbox']);
        Route::get('/workspace/vendor-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'vendorSearch']);
        Route::get('/workspace/customer-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'customerSearch']);
        Route::get('/workspace/account-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'accountSearch']);

        // Bank-SMS capture (Phase 4). /ingest is POSTed by the native SMS
        // receiver; the rest are the inbox one-tap actions. Regex parse, no LLM.
        Route::post('/sms/ingest', [\App\Http\Controllers\API\AssistantSmsController::class, 'ingest']);
        Route::post('/sms/{id}/draft', [\App\Http\Controllers\API\AssistantSmsController::class, 'draft']);
        Route::post('/sms/{id}/match-credit', [\App\Http\Controllers\API\AssistantSmsController::class, 'matchCredit']);
        Route::get('/sms/{id}/targets', [\App\Http\Controllers\API\AssistantSmsController::class, 'targets']);
        Route::post('/sms/{id}/attach', [\App\Http\Controllers\API\AssistantSmsController::class, 'attach']);
        Route::post('/sms/{id}/ignore', [\App\Http\Controllers\API\AssistantSmsController::class, 'ignore']);
        Route::post('/sms/{id}/teach-sender', [\App\Http\Controllers\API\AssistantSmsController::class, 'teachSender']);
        Route::post('/sms/{id}/save-map', [\App\Http\Controllers\API\AssistantSmsController::class, 'saveMap']);

        // Tag-on-entry: "you just recorded a payment — is this its bank SMS?"
        // Same endpoints the web uses, so phone and web behave identically.
        Route::get('/money-out/candidates', [\App\Http\Controllers\API\AssistantSmsController::class, 'tagCandidates']);
        Route::post('/money-out/tag', [\App\Http\Controllers\API\AssistantSmsController::class, 'tagToEntry']);
        Route::get('/money-out/for-entry', [\App\Http\Controllers\API\AssistantSmsController::class, 'smsForEntry']);

        // Movement log — what payments changed hands (self-heal visibility).
        Route::get('/money-moves', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'moneyMoves']);
        Route::post('/sms/{id}/restore', [\App\Http\Controllers\API\AssistantSmsController::class, 'restore']);
    });

    // ============================
    // Orders (Mobile Store Mode)
    // ============================
    Route::post('/orders', [\App\Http\Controllers\CRM\OrderController::class, 'store']);
    Route::get('/orders/{id}', [\App\Http\Controllers\CRM\OrderController::class, 'show']);
    
    // ============================
    // Reports (Mobile Store Mode)
    // ============================
    Route::prefix('reports')->group(function () {
        Route::get('/monthly-summary', [\App\Http\Controllers\API\ReportsController::class, 'getMonthlySummary']);
        Route::get('/month-details', [\App\Http\Controllers\API\ReportsController::class, 'getMonthDetails']);
        Route::get('/vendor-daily', [\App\Http\Controllers\API\ReportsController::class, 'getVendorDailyReport']);
        Route::get('/expense-daily', [\App\Http\Controllers\API\ReportsController::class, 'getExpenseDailyReport']);
        Route::get('/daily-summary', [\App\Http\Controllers\API\ReportsController::class, 'getDailySummary']);
        Route::get('/daily-details', [\App\Http\Controllers\API\ReportsController::class, 'getDailyDetails']);
    });
}); // Close auth:sanctum middleware group