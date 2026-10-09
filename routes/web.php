<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SysAdmin\MenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\CRM\OrderController;

// Redirect root to demo1
Route::get('/', function () {
    return redirect('/auth/login');
});

// APK Download - serves the latest version directly (no auth required)
Route::get('/apk/latest', function () {
    $path = public_path('downloads/NizamiFarms-Rider.apk');
    if (file_exists($path)) {
        return response()->download($path, 'NizamiFarms-Rider.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return abort(404, 'APK not found');
})->name('apk.latest');

// Qurbani companion APK — temporary secondary build during the Qurbani event
// that locks the app to QURBANI mode so a rider can Alt-tab natively between
// the primary NF app and a Qurbani-only build without switching modes.
// Package id: com.nizamifarmsmobile.qurbani (installs side-by-side with main).
Route::get('/apk/qurbani-latest', function () {
    $path = public_path('downloads/NizamiFarms-Qurbani.apk');
    if (file_exists($path)) {
        return response()->download($path, 'NizamiFarms-Qurbani.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return abort(404, 'Qurbani APK not found');
})->name('apk.qurbani.latest');

// NF Messages APK — the dedicated WhatsApp-style messaging app for management.
// Package id: com.nizamifarmsmobile.messages (installs side-by-side with the
// primary NF app and the Qurbani build). Placed by build-production-apk-auto.bat.
Route::get('/apk/messages-latest', function () {
    $path = public_path('downloads/NizamiFarms-Messages.apk');
    if (file_exists($path)) {
        return response()->download($path, 'NizamiFarms-Messages.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return abort(404, 'Messages APK not found');
})->name('apk.messages.latest');

// Serve files from storage/app/public without symlink (no auth required)
Route::get('/public-storage/{path}', [\App\Http\Controllers\FileController::class, 'publicStorage'])->where('path', '.*');

Route::group([
    'prefix' => 'auth'
], function ($router) {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/authenticate', [AuthController::class, 'authenticate'])->name('authenticate');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Protected routes (requires authentication)
Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'auth'
], function ($router) {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::get('/refresh', [AuthController::class, 'refresh']);
    Route::post('/menu', [MenuController::class, 'list']);
});

// Web logout - always accessible, clears session even if token expired
Route::get('/logout', [AuthController::class, 'webLogout'])->name('web.logout');


// AppSheet webhook routes REMOVED (Jul 2026) — AppSheet is retired. These were the ONLY
// unauthenticated write endpoints into attendance/orders; kept nothing behind. The controller
// (app/Http/Controllers/Webhook/AppSheetController.php) and its CSRF exclusion in
// bootstrap/app.php were removed at the same time. Do not re-add without auth.

// Invoice PDF route - accessible by both web (session) and mobile (token) auth
Route::middleware(['auth:sanctum,web'])->group(function () {
    Route::get('/orders/{id}/invoice/pdf', [OrderController::class, 'invoicePdf'])->name('orders.invoice.pdf');
});

Route::middleware(['auth'])->group(function () {
    // ── NF Assistant on the WEB (ASSISTANT-VIEW-PLAN-JUL2026.md §4) ──────────
    // The page shell + the SAME assistant controllers the phone uses, re-routed
    // under web session auth (they gate on hasMobilePermission('use_ai_assistant')
    // via Auth::user(), which works for both). Additive — the /api/assistant/*
    // sanctum routes the mobile app calls are untouched. Names prefixed
    // 'assistant-view.' so they never collide.
    Route::prefix('assistant-view')->name('assistant-view.')->group(function () {
        Route::get('/', [\App\Http\Controllers\AssistantViewController::class, 'index'])->name('index');

        // Read (money box + chat history) — pure DB / stored transcript.
        Route::get('/workspace/summary', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'summary'])->name('summary');
        Route::get('/workspace/inbox', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'inbox'])->name('inbox');
        Route::get('/workspace/vendor-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'vendorSearch'])->name('vendor-search');
        Route::get('/workspace/customer-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'customerSearch'])->name('customer-search');
        Route::get('/workspace/account-search', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'accountSearch'])->name('account-search');
        Route::get('/history', [\App\Http\Controllers\API\AssistantController::class, 'history_endpoint'])->name('history');
        Route::get('/settings', [\App\Http\Controllers\API\AssistantSettingsController::class, 'index'])->name('settings');

        // Act — the same endpoints, so web actions == phone actions.
        Route::post('/message', [\App\Http\Controllers\API\AssistantController::class, 'message'])->name('message');
        Route::post('/confirm/{draft}', [\App\Http\Controllers\API\AssistantController::class, 'confirm'])->name('confirm');
        Route::post('/cancel/{draft}', [\App\Http\Controllers\API\AssistantController::class, 'cancel'])->name('cancel');
        Route::post('/draft/{draft}/choose', [\App\Http\Controllers\API\AssistantController::class, 'choose'])->name('choose');
        Route::post('/new-topic', [\App\Http\Controllers\API\AssistantController::class, 'newTopic'])->name('new-topic');
        Route::post('/sms/{id}/match-credit', [\App\Http\Controllers\API\AssistantSmsController::class, 'matchCredit'])->name('sms.match');
        // Aug-2026 — re-point a credit at an EXACT order (incl. already-approved
        // ones), so a wrong tag is always fixable.
        Route::get('/sms/{id}/targets', [\App\Http\Controllers\API\AssistantSmsController::class, 'targets'])->name('sms.targets');
        Route::post('/sms/{id}/attach', [\App\Http\Controllers\API\AssistantSmsController::class, 'attach'])->name('sms.attach');
        Route::post('/sms/{id}/ignore', [\App\Http\Controllers\API\AssistantSmsController::class, 'ignore'])->name('sms.ignore');
        Route::post('/sms/{id}/restore', [\App\Http\Controllers\API\AssistantSmsController::class, 'restore'])->name('sms.restore');
        Route::post('/sms/{id}/teach-sender', [\App\Http\Controllers\API\AssistantSmsController::class, 'teachSender'])->name('sms.teach');
        Route::post('/sms/{id}/draft', [\App\Http\Controllers\API\AssistantSmsController::class, 'draft'])->name('sms.draft');
        Route::post('/sms/{id}/save-map', [\App\Http\Controllers\API\AssistantSmsController::class, 'saveMap'])->name('sms.save-map');

        // Tag-on-entry: "you just recorded a payment — is this its bank SMS?"
        Route::get('/money-out/candidates', [\App\Http\Controllers\API\AssistantSmsController::class, 'tagCandidates'])->name('money-out.candidates');
        Route::post('/money-out/tag', [\App\Http\Controllers\API\AssistantSmsController::class, 'tagToEntry'])->name('money-out.tag');
        Route::get('/money-out/for-entry', [\App\Http\Controllers\API\AssistantSmsController::class, 'smsForEntry'])->name('money-out.for-entry');

        // Movement log — what payments changed hands (self-heal visibility).
        Route::get('/money-moves', [\App\Http\Controllers\API\AssistantWorkspaceController::class, 'moneyMoves'])->name('money-moves');
    });

    // Dashboard main routes
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/enhanced', [DashboardController::class, 'enhanced']); // New enhanced dashboard
    Route::get('/dashboard/kpis', [DashboardController::class, 'getKPIs']);
    Route::get('/dashboard/revenue-chart', [DashboardController::class, 'getRevenueChart']);
    Route::get('/dashboard/customer-growth-chart', [DashboardController::class, 'getCustomerGrowthChart']);
    Route::get('/dashboard/monthly-analytics', [DashboardController::class, 'getMonthlyAnalytics']);
    Route::get('/dashboard/daily-analytics', [DashboardController::class, 'getDailyAnalytics']);
    Route::get('/dashboard/general-stats', [DashboardController::class, 'getGeneralStats']);
    Route::post('/dashboard/clear-cache', [DashboardController::class, 'clearCache']);
    
    // Enhanced dashboard analytics routes
    Route::get('/dashboard/top-cards', [DashboardController::class, 'getTopCards']);
    Route::get('/dashboard/financial-summary', [DashboardController::class, 'getFinancialSummary']);
    Route::get('/dashboard/order-source-summary', [DashboardController::class, 'getOrderSourceSummary']);
    Route::get('/dashboard/customer-analysis', [DashboardController::class, 'getCustomerAnalysis']);
    Route::get('/dashboard/product-categories', [DashboardController::class, 'getProductCategories']);
    Route::get('/dashboard/weekly-performance', [DashboardController::class, 'getWeeklyPerformance']);
    Route::get('/dashboard/mom-growth', [DashboardController::class, 'getMonthOverMonthGrowth']);
    Route::get('/dashboard/customer-cohort', [DashboardController::class, 'getCustomerCohort']);
    Route::get('/dashboard/orders-for-date', [DashboardController::class, 'getOrdersForDate']);
    Route::get('/dashboard/monthly-ledger-analytics', [DashboardController::class, 'getMonthlyLedgerAnalytics']);
    Route::get('/dashboard/ledger-transactions', [DashboardController::class, 'getLedgerTransactions']);
    Route::get('/dashboard/monthly-product-categories', [DashboardController::class, 'getMonthlyProductCategories']);
    Route::get('/dashboard/daily-product-categories', [DashboardController::class, 'getDailyProductCategories']);
    Route::get('/dashboard/active-customers-list', [DashboardController::class, 'getActiveCustomersList']);
    Route::get('/dashboard/customer-dormancy', [DashboardController::class, 'getCustomerDormancy']);
    Route::get('/dashboard/customer-dormancy-list', [DashboardController::class, 'getCustomerDormancyList']);
    Route::get('/dashboard/customer-list', [DashboardController::class, 'getCustomerList']);
    Route::get('/dashboard/month-customer-stats', [DashboardController::class, 'getMonthCustomerStats']);
    Route::get('/dashboard/frozen-sales', [DashboardController::class, 'getFrozenSales']);

    // ── HQ Executive Dashboard (Jul-2026, Phase 1) ─────────────────────────
    // Read-only CEO "Monthly Closing" view. Additive: its own controller +
    // service, no operational endpoint touched. Access gated in-controller to
    // the same audience as /dashboard (invoices-only supervisors blocked).
    Route::prefix('hq')->group(function () {
        Route::get('/', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'index'])->name('hq.index');
        Route::get('/closing', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'closing']);
        Route::get('/trend', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'trend']);
        Route::get('/order-split', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'orderSplit']);
        Route::get('/growth', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'growth']);
        Route::get('/working-capital', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'workingCapital']);
        Route::get('/drill/recency', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'recency']);
        Route::get('/drill/revenue-daily', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'revenueDaily']);
        Route::get('/drill/revenue-orders', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'revenueOrders']);
        Route::get('/drill/vendors', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'vendors']);
        Route::get('/drill/vendor', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'vendorDetail']);
        Route::get('/drill/expenses', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'expenses']);
        Route::get('/drill/expense', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'expenseDetail']);
        Route::get('/drill/salaries', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'salaries']);
        Route::get('/drill/salary-employee', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'salaryDetail']);
        // Returns (Sep-2026): card → the orders behind it → one order's items + ledger rows.
        Route::get('/drill/returns', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'returns']);
        Route::get('/drill/returns-orders', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'returnsDetail']);
        Route::get('/drill/return', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'returnDetail']);
        Route::get('/drill/customers', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'customers']);
        Route::get('/drill/receivables', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'receivables']);
        Route::get('/drill/payables', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'payables']);
        Route::get('/drill/assets', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'assets']);
        Route::get('/drill/asset-purchases', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'assetPurchases']);
        Route::get('/products', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'products']);
        Route::get('/drill/products-category', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'productsCategory']);
        Route::get('/drill/product-daily', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'productDaily']);
        Route::get('/drill/missing-invoices', [\App\Http\Controllers\HQ\ExecutiveDashboardController::class, 'missingInvoices']);
    });


    // Log viewer routes
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/logs/data', [LogController::class, 'getLogs']);
    Route::get('/logs/summary', [LogController::class, 'getSummary']);
    Route::get('/logs/dates', [LogController::class, 'getAvailableDates']);
    Route::get('/logs/info', [LogController::class, 'getLogInfo']);
    Route::post('/logs/clear-old', [LogController::class, 'clearOldLogs']);
    Route::get('/logs/export', [LogController::class, 'exportLogs']);
    Route::get('/logs/test', [LogController::class, 'testLogging']);
    // Customer-app traffic stats (Jul-2026) — aggregates the customer_app log
    // channel so the rate limit can be sized from observed traffic.
    Route::get('/customer-app-stats', [\App\Http\Controllers\CustomerAppStatsController::class, 'index']);
    // Audit trail (Jul-2026, Phase 2 L1) — who changed what on orders/ledger/payments.
    Route::get('/audit-log', [\App\Http\Controllers\AuditLogController::class, 'index']);
    // Operations dashboard page (imports, bulk delivery status)
    Route::get('/admin/operations', function () {
        // App-release broadcast card: current served version + reachable devices.
        $appUpdateInfo = [
            'version' => app(\App\Http\Controllers\API\AppController::class)
                ->getLatestVersion()->getData(true)['version'],
            'active_devices' => \Illuminate\Support\Facades\DB::table('t_wa_device_tokens')
                ->where('is_active', 1)->distinct()->count('fcm_token'),
        ];
        return view('admin.operations', compact('appUpdateInfo'));
    })->name('admin.operations')->middleware('block.rider');
    // Sep-2026 — barcode scan verification switch ('single' | 'double'), read by
    // the store scanner through /rider/permissions.
    // ⚠ PATH SHAPE IS LOAD-BEARING: prod's StackProtect bot filter challenges by URL
    // SHAPE, and every path it blocked ends in /settings or /config (it silently killed
    // /orders/delivery-scan/settings). This path carries neither word and mirrors
    // /admin/payments/reconcile — a POST fetched from this same Operations page that
    // works in production today. Verify with a browser fetch before trusting a rename.
    Route::post('/admin/operations/scan-verify', function (\Illuminate\Http\Request $request) {
        $mode = $request->input('mode') === 'double' ? 'double' : 'single';
        \App\Models\FIN\ConfigModel::set('barcode_scan_verify_mode', $mode,
            'Store barcode scanner: single = apply on first camera frame, double = wait for a second identical frame');
        return response()->json([
            'success' => true,
            'mode' => $mode,
            'message' => $mode === 'double'
                ? 'Verified scanning ON — every scan now waits for a second matching read (~0.3 s). Takes effect on each phone the next time the app is opened.'
                : 'Single-read scanning — labels apply on the first camera frame, as before. Takes effect on each phone the next time the app is opened.',
        ]);
    })->name('admin.operations.scan-verify')->middleware('block.rider');

    // Line-item quick-note presets (chips shown next to the per-line-item
    // "Add note" box). Shared team-wide list; same controller backs the mobile
    // API routes in routes/api.php. Kept off the /orders/* path so the
    // /orders/{id} wildcard never swallows it.
    Route::get('/line-item-quick-notes', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'index'])->name('line-item-quick-notes.index');
    Route::post('/line-item-quick-notes', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'store'])->name('line-item-quick-notes.store');
    Route::delete('/line-item-quick-notes/{id}', [\App\Http\Controllers\CRM\LineItemQuickNoteController::class, 'destroy'])->name('line-item-quick-notes.destroy');

    // Per-user UI preferences (Phase 5 — "Customize menu"). Scoped to auth()->id();
    // a user only ever reads/writes their own row. Guarded server-side against a
    // missing t_sys_user_setting table, so the feature is inert until the SQL runs.
    Route::get('/me/settings/sidebar', [\App\Http\Controllers\SysAdmin\UserSettingController::class, 'getSidebar'])->name('me.settings.sidebar.get');
    Route::post('/me/settings/sidebar', [\App\Http\Controllers\SysAdmin\UserSettingController::class, 'saveSidebar'])->name('me.settings.sidebar.save');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/filter', [OrderController::class, 'filter'])->name('orders.filter');
    // Jul-2026 — cheap live count for the Approvals right-drawer badge (30s poll).
    Route::get('/orders/approvals-count', [OrderController::class, 'approvalsCount'])->name('orders.approvals-count');
    Route::get('/orders/sync-status', [OrderController::class, 'syncStatus'])->name('orders.sync-status'); // ⭐ SMART SYNC (must be before {id})
    Route::get('/orders/geocode-pending', [OrderController::class, 'geocodePendingCustomers'])->name('orders.geocode-pending'); // ⭐ Background geocoding
    Route::get('/orders/open-status-counts', [OrderController::class, 'getOpenOrdersStatusCounts'])->name('orders.open-status-counts');
    Route::get('/orders/rider-counts', [OrderController::class, 'getRiderOrdersCounts'])->name('orders.rider-counts');
    Route::get('/orders/new-since', [OrderController::class, 'newOrdersSince'])->name('orders.new-since'); // "N new orders" pill probe (must be before {id})
    // Aug-2026 — riders waiting on a locked verified pin (30s banner poll; must be before {id}).
    Route::get('/orders/pin-unlock-requests', [OrderController::class, 'pinUnlockRequests'])->name('orders.pin-unlock-requests');
    // 🔁 Sep-2026 — riders waiting for a vehicle handover to be approved. Same 30s
    // banner poll and the same "must be before {id}" rule as the line above.
    // Approving here runs the SAME VehicleService::assign() the fleet screen runs.
    Route::get('/orders/vehicle-requests', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'pending'])->name('orders.vehicle-requests');
    Route::post('/orders/vehicle-requests/{id}/approve', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'approve'])->name('orders.vehicle-requests.approve');
    Route::post('/orders/vehicle-requests/{id}/reject', [\App\Http\Controllers\CRM\VehicleHandoverController::class, 'reject'])->name('orders.vehicle-requests.reject');

    // ⭐ RIDERS MAP: Standalone page
    Route::get('/riders-map', [OrderController::class, 'ridersMap'])->name('riders-map');
    
    // ⭐ RIDERS MAP: Location tracking API routes (web app)
    Route::get('/orders/riders-map/active', [\App\Http\Controllers\API\RiderController::class, 'getActiveRidersForMap'])->name('orders.riders-map.active');
    Route::get('/orders/riders-map/all-open-orders', [\App\Http\Controllers\API\RiderController::class, 'getAllOpenOrdersForMap'])->name('orders.riders-map.all-open');
    Route::get('/orders/riders-map/delivery-history', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryHistory'])->name('orders.riders-map.history');
    Route::get('/orders/riders-map/dispatch-report', [\App\Http\Controllers\API\RiderController::class, 'getDateDeliveryReport'])->name('orders.riders-map.dispatch-report');
    // Rider Reports (Phase 2) — real-time Manager Issues + Report Card data
    Route::get('/orders/riders-map/reports', [\App\Http\Controllers\CRM\RiderReportsController::class, 'index'])->name('orders.riders-map.reports');
    Route::get('/orders/riders-map/timeline', [\App\Http\Controllers\CRM\RiderReportsController::class, 'timeline'])->name('orders.riders-map.timeline');
    // Day Review (Jul-2026) — the tab that replaces History + Dispatch Tracker + Issues.
    // MUST stay above the '/orders/riders-map/{riderId}' catch-all below, or
    // 'day-review' would be swallowed as a rider id.
    Route::get('/orders/riders-map/day-review', [\App\Http\Controllers\CRM\RiderDayReviewController::class, 'day'])->name('orders.riders-map.day-review');
    Route::get('/orders/riders-map/day-review/rider', [\App\Http\Controllers\CRM\RiderDayReviewController::class, 'rider'])->name('orders.riders-map.day-review.rider');
    // Fleet & Fuel (Jul-2026) — per-rider fuel/maintenance cost per month.
    // Also above the '{riderId}' catch-all for the same reason as Day Review.
    Route::get('/orders/riders-map/fleet', [\App\Http\Controllers\CRM\FleetFuelController::class, 'month'])->name('orders.riders-map.fleet');
    Route::get('/orders/riders-map/fleet/rider', [\App\Http\Controllers\CRM\FleetFuelController::class, 'rider'])->name('orders.riders-map.fleet.rider');
    Route::post('/orders/riders-map/fleet/mark-serviced', [\App\Http\Controllers\CRM\FleetFuelController::class, 'markServiced'])->name('orders.riders-map.fleet.mark-serviced');
    // ✏️ Correct or remove a service record — same right as recording one. Being able to
    //    write a record and not fix it is what left log #8 needing hand-written SQL.
    Route::post('/orders/riders-map/fleet/service-records/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'amendServiceRecord'])->where('id', '[0-9]+')->name('fleet.service-record.amend');
    Route::delete('/orders/riders-map/fleet/service-records/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'deleteServiceRecord'])->where('id', '[0-9]+')->name('fleet.service-record.delete');
    // ✏️ Correct the odometer / job on a maintenance CLAIM (approved ones included). The money
    //    fields stay locked behind editClaim — see FleetFuelController::correctClaimReading.
    Route::post('/orders/riders-map/fleet/claim-readings/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'correctClaimReading'])->where('id', '[0-9]+')->name('fleet.claim-reading.correct');
    // 🏠 RIDER HOME LOCATION (Sep-2026) — read/save/remove from the Bikes tab, where a
    //    manager is already looking at the machine that sleeps there. The SAME controller
    //    methods back the phone (see routes/api.php), over RiderHomePinService, so the two
    //    surfaces cannot drift. Reading needs Bikes view rights; changing needs
    //    `assign_vehicles`, checked inside the controller.
    // ⚠ ABOVE nothing dangerous — 'home-pin' is its own path segment, not an id.
    Route::get('/orders/riders-map/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'homePinShow'])->where('userId', '[0-9]+')->name('fleet.home-pin.show');
    Route::post('/orders/riders-map/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'homePinSave'])->where('userId', '[0-9]+')->name('fleet.home-pin.save');
    Route::delete('/orders/riders-map/fleet/home-pin/{userId}', [\App\Http\Controllers\CRM\VehicleController::class, 'homePinClear'])->where('userId', '[0-9]+')->name('fleet.home-pin.clear');
    // 🧾 "Which recorded service is this bill for?" — the picker's list.
    Route::get('/orders/riders-map/fleet/unbilled-services', [\App\Http\Controllers\CRM\FleetFuelController::class, 'unbilledServices'])->name('fleet.unbilled-services');
    Route::post('/orders/riders-map/fleet/default-interval', [\App\Http\Controllers\CRM\FleetFuelController::class, 'setDefaultInterval'])->name('orders.riders-map.fleet.default-interval');
    // Which bikes hold their own schedule — shown before a company-wide change so the
    // manager decides whether to override them or leave them alone.
    Route::get('/orders/riders-map/fleet/interval-overrides', [\App\Http\Controllers\CRM\FleetFuelController::class, 'intervalOverrides'])->name('orders.riders-map.fleet.interval-overrides');
    // 🔧 Manager-editable maintenance types (Aug-2026). Reading is open to anyone
    // who can open Bikes (the pickers need it); writes are gated on
    // `manage_bike_service` inside the controller so they return JSON the modals
    // can show rather than an HTML redirect.
    Route::get('/orders/riders-map/fleet/maintenance-types', [\App\Http\Controllers\CRM\FleetFuelController::class, 'maintenanceTypes'])->name('orders.riders-map.fleet.maintenance-types');
    Route::post('/orders/riders-map/fleet/maintenance-types', [\App\Http\Controllers\CRM\FleetFuelController::class, 'saveMaintenanceType'])->name('orders.riders-map.fleet.maintenance-types.create');
    Route::post('/orders/riders-map/fleet/maintenance-types/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'saveMaintenanceType'])->name('orders.riders-map.fleet.maintenance-types.save');
    Route::delete('/orders/riders-map/fleet/maintenance-types/{id}', [\App\Http\Controllers\CRM\FleetFuelController::class, 'deleteMaintenanceType'])->name('orders.riders-map.fleet.maintenance-types.delete');
    // ⭐ "Which vehicles hold their own value for this job?" — asked BEFORE a class
    //   standard changes, so the manager decides what happens to them.
    Route::get('/orders/riders-map/fleet/maintenance-types/{id}/exceptions', [\App\Http\Controllers\CRM\FleetFuelController::class, 'typeExceptions'])->name('orders.riders-map.fleet.maintenance-types.exceptions');
    // ✏️ Correct a PENDING fuel/maintenance claim a rider already filed.
    Route::post('/orders/riders-map/fleet/claim/{id}/edit', [\App\Http\Controllers\CRM\FleetFuelController::class, 'editClaim'])->name('orders.riders-map.fleet.claim-edit');
    // 🏍️🚚 VEHICLE REGISTRY (Aug-2026) — bikes and the van as assignable machines.
    // Reading uses the same keys that open Bikes; every write is gated on the new
    // `assign_vehicles` permission INSIDE the controller, so a refusal comes back as
    // JSON the modal can show rather than an HTML redirect.
    // These sit above the '{riderId}' catch-all with the rest of the fleet routes.
    // 🚚 Van panel + meet-up stop management on the WEB (same controller the app
    // uses — one implementation, so the two surfaces cannot drift).
    Route::get('/orders/van/panel', [\App\Http\Controllers\API\VanController::class, 'storePanel'])->name('orders.van.panel');
    Route::get('/orders/van/stops', [\App\Http\Controllers\API\VanController::class, 'stops'])->name('orders.van.stops');
    // ⭐ Send the van driver's OWN parked stops out and time them, from the web
    //    (Aug-30). Same controller action his picker calls — it takes `driver_id`
    //    and checks the store permission itself. This replaces the `on_hold`→
    //    `out_for_delivery` laundering the store had to use twice on 29 Aug,
    //    which stripped the ETA and left no van history behind.
    Route::post('/orders/van/dispatch-selected', [\App\Http\Controllers\API\VanController::class, 'dispatchSelected'])->name('orders.van.dispatch-selected');
    // 🆘 Sep-2026: the two repair doors the van card's refusal text always named
    //    but nothing ever wired — a manager's no-scan handover (label unreadable,
    //    dead phone) and taking a box back off the van. Same controller actions
    //    the app calls; both check `assign_riders` themselves.
    Route::post('/orders/van/orders/{id}/handover-override', [\App\Http\Controllers\API\VanController::class, 'handoverOverride'])->name('orders.van.handover-override');
    Route::post('/orders/van/orders/{id}/unload', [\App\Http\Controllers\API\VanController::class, 'unload'])->name('orders.van.unload');
    Route::post('/orders/van/stops', [\App\Http\Controllers\API\VanController::class, 'saveStop'])->name('orders.van.stops.create');
    Route::post('/orders/van/stops/promote/{handoverId}', [\App\Http\Controllers\API\VanController::class, 'promoteStop'])->name('orders.van.stops.promote');
    // ⭐ Send a van to a meet-up point from the WEB — the same controller action
    //    the store's mobile board and the driver's own picker call, so all three
    //    surfaces do exactly one thing and cannot drift (owner ruling Aug-4).
    //    Declared before '/stops/{id}' so 'set' is never read as an id.
    Route::post('/orders/van/stops/set', [\App\Http\Controllers\API\VanController::class, 'setStop'])->name('orders.van.stops.set');
    Route::post('/orders/van/stops/{id}', [\App\Http\Controllers\API\VanController::class, 'saveStop'])->name('orders.van.stops.save');
    Route::delete('/orders/van/stops/{id}', [\App\Http\Controllers\API\VanController::class, 'retireStop'])->name('orders.van.stops.retire');

    Route::get('/orders/riders-map/fleet/vehicles', [\App\Http\Controllers\CRM\VehicleController::class, 'index'])->name('orders.riders-map.fleet.vehicles');
    // "He was on a different bike that day" — the only writer of attendance.vehicle_id.
    // Declared BEFORE '/vehicles/{id}' so 'day-override' is not read as a vehicle id.
    // ⭐ Aug-2026 — METER READINGS FROM THE VEHICLES PAGE. A rider-day carries ONE
    //   set of meters and the engine maps it to ONE machine, so a mid-day second
    //   machine (the van handed over at noon) was unrecordable. These read/write the
    //   MACHINE side. Gate = `manage_bike_service` (owner ruling: Shabib + admins +
    //   Qasim). Correcting a RIDER's own attendance reading additionally mirrors the
    //   Attendance page's own `block.rider` lock — inside the controller AND here —
    //   so the same act can never have two different locks.
    // ⚠ MUST stay above the '/vehicles/{id}' catch-all or {id} swallows the word.
    Route::get('/orders/riders-map/fleet/vehicles/{id}/meter-day', [\App\Http\Controllers\CRM\VehicleController::class, 'meterDay'])->name('orders.riders-map.fleet.vehicles.meter-day');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/meter-save', [\App\Http\Controllers\CRM\VehicleController::class, 'meterSave'])->middleware('block.rider')->name('orders.riders-map.fleet.vehicles.meter-save');
    Route::post('/orders/riders-map/fleet/vehicles/day-override', [\App\Http\Controllers\CRM\VehicleController::class, 'dayOverride'])->name('orders.riders-map.fleet.vehicles.day-override');
    Route::get('/orders/riders-map/fleet/vehicles/rider-days', [\App\Http\Controllers\CRM\VehicleController::class, 'riderDays'])->name('orders.riders-map.fleet.vehicles.rider-days');
    // Which machine a claim would land on. ⚠ ABOVE '/vehicles/{id}' — otherwise
    // 'for-user' is read as a vehicle id (same trap as day-override).
    Route::get('/orders/riders-map/fleet/vehicles/for-user', [\App\Http\Controllers\CRM\VehicleController::class, 'forUser'])->name('orders.riders-map.fleet.vehicles.for-user');
    // ⭐ What a rider actually rode on a date, per machine, with the kilometres and
    //   whether they have already been claimed — the New-petrol modal's vehicle picker.
    //   ⚠ ABOVE '/vehicles/{id}', same trap as for-user/day-override.
    Route::get('/orders/riders-map/fleet/vehicles/petrol-context', [\App\Http\Controllers\CRM\VehicleController::class, 'petrolContext'])->name('orders.riders-map.fleet.vehicles.petrol-context');
    Route::post('/orders/riders-map/fleet/vehicles', [\App\Http\Controllers\CRM\VehicleController::class, 'save'])->name('orders.riders-map.fleet.vehicles.create');
    Route::delete('/orders/riders-map/fleet/vehicles/photo/{photoId}', [\App\Http\Controllers\CRM\VehicleController::class, 'deletePhoto'])->name('orders.riders-map.fleet.vehicles.photo-delete');
    Route::get('/orders/riders-map/fleet/vehicles/{id}', [\App\Http\Controllers\CRM\VehicleController::class, 'show'])->name('orders.riders-map.fleet.vehicles.show');
    // The month's kilometres day by day — lazy, only when a manager asks for it.
    Route::get('/orders/riders-map/fleet/vehicles/{id}/days', [\App\Http\Controllers\CRM\VehicleController::class, 'days'])->name('orders.riders-map.fleet.vehicles.days');
    // ⭐⭐ THE PER-VEHICLE PER-JOB SCHEDULE (Sep-2026) — what replaced the single-number
    //   "This bike's schedule" prompt. Gated on `manage_bike_service`, NOT
    //   `assign_vehicles`: Qasim owns maintenance and holds no assignment key.
    Route::get('/orders/riders-map/fleet/vehicles/{id}/schedule', [\App\Http\Controllers\CRM\VehicleController::class, 'schedule'])->name('orders.riders-map.fleet.vehicles.schedule');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/schedule', [\App\Http\Controllers\CRM\VehicleController::class, 'saveSchedule'])->name('orders.riders-map.fleet.vehicles.schedule.save');
    // 🛢 Service-due banner (dismissable). Audience is decided inside the service,
    // not by a route permission — see BikeServiceAlerts::forUser().
    Route::get('/orders/riders-map/fleet/service-alerts', [\App\Http\Controllers\CRM\VehicleController::class, 'serviceAlerts'])->name('orders.riders-map.fleet.service-alerts');
    Route::post('/orders/riders-map/fleet/service-alerts/dismiss', [\App\Http\Controllers\CRM\VehicleController::class, 'dismissServiceAlert'])->name('orders.riders-map.fleet.service-alerts.dismiss');

    // 🛠 BIKE TICKETS (Sep-2026) — the same controller the phone calls. No permission
    // middleware: the audience rule lives in VehicleTicketService so the desk and the
    // phone cannot enforce it differently.
    /**
     * 🛠 THE ISSUES BOARD (Sep-15 2026) — the fleet's open problems grouped BY MACHINE.
     *
     * ⚠ MUST stay above `fleet/tickets/{id}` — `issues` is not numeric so the `{id}` pattern
     *   would not swallow it today, but the Sep-15 Daily Closing round lost a whole route to
     *   exactly this shape and the cost of being explicit is one comment.
     * ⚠ No permission middleware, deliberately, like the ticket routes beneath it: the audience
     *   (manager / rider / read-only planner) is decided inside VehicleIssueBoard so the desk
     *   and the phone cannot enforce it differently.
     */
    Route::get('/orders/riders-map/fleet/issues', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'board'])->name('fleet.issues');
    /**
     * 🔎 THE PLANNERS' READ-ONLY DOOR (owner ruling, 15-Sep). Its own PAGE, not a Bikes tab:
     * Farooq's only role is typed `rider`, and `OrderController::ridersMap()` turns away any
     * role-typed rider before a single bike key is consulted — so the tab could never reach him.
     * The page gate is "do you hold one of the keys", never a role type; a plain rider holds none.
     */
    Route::get('/orders/riders-map/fleet/issues-board', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'boardPage'])->name('fleet.issues.page');

    Route::get('/orders/riders-map/fleet/tickets', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'index'])->name('fleet.tickets.index');
    Route::post('/orders/riders-map/fleet/tickets', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'store'])->name('fleet.tickets.store');
    Route::get('/orders/riders-map/fleet/tickets/alerts', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'alerts'])->name('fleet.tickets.alerts');
    Route::get('/orders/riders-map/fleet/tickets/{id}', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'show'])->name('fleet.tickets.show')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/tickets/{id}/reply', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'reply'])->name('fleet.tickets.reply')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/tickets/{id}/close', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'close'])->name('fleet.tickets.close')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/tickets/{id}/reopen', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'reopen'])->name('fleet.tickets.reopen')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/tickets/{id}/read', [\App\Http\Controllers\CRM\VehicleTicketController::class, 'markRead'])->name('fleet.tickets.read')->where('id', '[0-9]+');

    // 🔧 WORKSHOP VISITS (Sep-2026) — same controller the phone calls, so the desk and
    // the phone cannot enforce "who may schedule" differently.
    Route::get('/orders/riders-map/fleet/workshop', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'index'])->name('fleet.workshop.index');
    Route::post('/orders/riders-map/fleet/workshop', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'store'])->name('fleet.workshop.store');
    Route::get('/orders/riders-map/fleet/workshop/alerts', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'alerts'])->name('fleet.workshop.alerts');
    Route::get('/orders/riders-map/fleet/workshop/warnings', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'warnings'])->name('fleet.workshop.warnings');
    Route::get('/orders/riders-map/fleet/workshop/outcome', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'outcome'])->name('fleet.workshop.outcome');
    // ⏳ APPROVAL (6-Sep ruling) — the shift planners' three doors. `manage_shifts` is
    //    checked inside WorkshopVisitService, the one place that answers "is he a planner?".
    Route::get('/orders/riders-map/fleet/workshop/approvals', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'approvals'])->name('fleet.workshop.approvals');
    // 📍 Add a workshop inline from the booking modal — same writer the phone uses.
    Route::post('/orders/riders-map/fleet/workshop/locations', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'addWorkshopLocation'])->name('fleet.workshop.locations.store');
    Route::post('/orders/riders-map/fleet/workshop/{id}/approve', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'approve'])->name('fleet.workshop.approve')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/decline', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'decline'])->name('fleet.workshop.decline')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/accept', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'accept'])->name('fleet.workshop.accept')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/cancel', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'cancel'])->name('fleet.workshop.cancel')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/done', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'done'])->name('fleet.workshop.done')->where('id', '[0-9]+');
    // 🚦 The trip: a manager may press "he has gone" for a rider who forgot; `live` feeds the
    //    corner notice on the orders page so nobody assigns work to a man at the workshop.
    Route::post('/orders/riders-map/fleet/workshop/{id}/depart', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'depart'])->name('fleet.workshop.depart')->where('id', '[0-9]+');
    Route::get('/orders/riders-map/fleet/workshop/live', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'live'])->name('fleet.workshop.live');
    /**
     * ⏰ "Later (6h)" on the approval card — the desk twin of the phone's button, per USER and
     *    never a decision, so one planner putting it aside never hides it from the others.
     * 📍 …and a manager's "he is there" for a workshop that has no pin to geofence against.
     */
    Route::get('/orders/riders-map/fleet/workshop/{id}/types', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'visitTypes'])->name('fleet.workshop.types')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/snooze', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'snooze'])->name('fleet.workshop.snooze')->where('id', '[0-9]+');
    Route::post('/orders/riders-map/fleet/workshop/{id}/arrived-here', [\App\Http\Controllers\CRM\WorkshopVisitController::class, 'arrivedHere'])->name('fleet.workshop.arrivedhere')->where('id', '[0-9]+');
    Route::get('/orders/riders-map/fleet/vehicles/{id}/preview-assign', [\App\Http\Controllers\CRM\VehicleController::class, 'previewAssign'])->name('orders.riders-map.fleet.vehicles.preview-assign');
    // Who loses this machine if it is taken back — feeds the "and what about him?" prompt.
    Route::get('/orders/riders-map/fleet/vehicles/{id}/preview-release', [\App\Http\Controllers\CRM\VehicleController::class, 'releasePreview'])->name('orders.riders-map.fleet.vehicles.preview-release');
    Route::post('/orders/riders-map/fleet/vehicles/{id}', [\App\Http\Controllers\CRM\VehicleController::class, 'save'])->name('orders.riders-map.fleet.vehicles.save');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/assign', [\App\Http\Controllers\CRM\VehicleController::class, 'assign'])->name('orders.riders-map.fleet.vehicles.assign');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/release', [\App\Http\Controllers\CRM\VehicleController::class, 'release'])->name('orders.riders-map.fleet.vehicles.release');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/base', [\App\Http\Controllers\CRM\VehicleController::class, 'setBase'])->name('orders.riders-map.fleet.vehicles.base');
    Route::post('/orders/riders-map/fleet/vehicles/{id}/photos', [\App\Http\Controllers\CRM\VehicleController::class, 'addPhotos'])->name('orders.riders-map.fleet.vehicles.photos');
    Route::get('/orders/riders-map/live-status', [\App\Http\Controllers\API\RiderController::class, 'getRidersLiveStatus'])->name('orders.riders-map.live-status');
    Route::get('/orders/riders-map/riders-for-history', [\App\Http\Controllers\API\RiderController::class, 'getRidersForHistory'])->name('orders.riders-map.riders-for-history');
    Route::get('/orders/riders-map/rider-history/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderDeliveryHistory'])->name('orders.riders-map.rider-delivery-history');
    Route::get('/orders/riders-map/{riderId}/location-history', [\App\Http\Controllers\API\RiderController::class, 'getRiderLocationHistory'])->name('orders.riders-map.rider-history');
    Route::get('/orders/riders-map/{riderId}', [\App\Http\Controllers\API\RiderController::class, 'getRiderMapData'])->name('orders.riders-map.rider');
    Route::get('/orders/delivery-journey/{orderId}', [\App\Http\Controllers\API\RiderController::class, 'getDeliveryJourney'])->name('orders.delivery-journey');
    Route::get('/orders/open-quantities', [OrderController::class, 'openQuantities'])->name('orders.open-quantities');
    Route::get('/orders/open-quantities/data', [OrderController::class, 'openQuantitiesData'])->name('orders.open-quantities.data');
    Route::get('/orders/open-quantities/settings', [OrderController::class, 'getOpenQuantitiesSettings'])->name('orders.open-quantities.settings.get');
    Route::post('/orders/open-quantities/settings', [OrderController::class, 'saveOpenQuantitiesSettings'])->name('orders.open-quantities.settings.save');
    // Phase 3 delivery package-scan toggles (operations area on the orders page). Static paths,
    // defined BEFORE /orders/{id} so they are not captured as an {id}.
    // ⚠ Aug-2026: renamed off the /settings-shaped path for the SAME reason as the receipt routes
    // below — StackProtect was challenging /orders/delivery-scan/settings on prod, so this modal
    // could silently fail to load its state or save it. `/orders/delivery-scan-flags` was
    // curl-verified against prod before the rename. It is a SINGLE-segment /orders/... path, so it
    // must stay above /orders/{id} or the catch-all swallows it (it demonstrably does).
    Route::get('/orders/delivery-scan-flags', [OrderController::class, 'getDeliveryScanSettings'])->name('orders.delivery-scan.flags.get');
    Route::post('/orders/delivery-scan-flags', [OrderController::class, 'saveDeliveryScanSettings'])->name('orders.delivery-scan.flags.save');
    // Deprecated aliases (old challenged path). Kept one release so a partial upload cannot strand
    // the modal; delete once both the route file and the blade have shipped together.
    Route::get('/orders/delivery-scan/settings', [OrderController::class, 'getDeliveryScanSettings'])->name('orders.delivery-scan.settings.get');
    Route::post('/orders/delivery-scan/settings', [OrderController::class, 'saveDeliveryScanSettings'])->name('orders.delivery-scan.settings.save');
    // Scan help (Aug-2026): riders asking a manager to approve a short delivery. Two-segment paths,
    // and the words "settings"/"config" are avoided deliberately — both were curl-verified as
    // reaching Laravel on prod (its own 404) before any code existed.
    Route::get('/orders/scan-help/pending', [OrderController::class, 'scanHelpPending'])->name('orders.scan-help.pending');
    Route::post('/orders/scan-help/decide', [OrderController::class, 'scanHelpDecide'])->name('orders.scan-help.decide');

    // RETURNS (Sep-2026) — a delivered order comes back. Money + goods, decided
    // on a form; see OrderReturnService.
    // ⚠ `/orders/returns/...` is three segments, so the /orders/{id} catch-all
    // below cannot swallow it — but these still sit ABOVE it, like every other
    // static orders path here, because that rule has been broken twice.
    Route::get('/orders/returns/pending', [\App\Http\Controllers\CRM\OrderReturnController::class, 'pending'])->name('orders.returns.pending');
    Route::get('/orders/returns/{returnId}/detail', [\App\Http\Controllers\CRM\OrderReturnController::class, 'detail'])->name('orders.returns.detail');
    Route::get('/orders/{id}/return/preview', [\App\Http\Controllers\CRM\OrderReturnController::class, 'preview'])->name('orders.return.preview');
    Route::post('/orders/{id}/return', [\App\Http\Controllers\CRM\OrderReturnController::class, 'store'])->name('orders.return.store');
    // Receipt printout field config (operations "More" menu). Static paths before /orders/{id}.
    // ⚠ The path deliberately avoids the words "config"/"settings": the host's StackProtect bot
    // filter challenges /settings-shaped URLs BEFORE they reach Laravel (verified Aug-2026 — see
    // WORKLOG), which broke this modal on prod while dev worked. Same handlers, new address.
    Route::get('/orders/receipt-print-fields', [OrderController::class, 'getReceiptPrintConfig'])->name('orders.receipt-config.settings.get');
    Route::post('/orders/receipt-print-fields', [OrderController::class, 'saveReceiptPrintConfig'])->name('orders.receipt-config.settings.save');
    // Deprecated aliases of the two routes above (old path, blocked by StackProtect on prod).
    // Kept one release so a partial upload can never strand the modal; delete after both files ship.
    Route::get('/orders/receipt-config/settings', [OrderController::class, 'getReceiptPrintConfig'])->name('orders.receipt-config.settings.get.legacy');
    Route::post('/orders/receipt-config/settings', [OrderController::class, 'saveReceiptPrintConfig'])->name('orders.receipt-config.settings.save.legacy');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{id}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('/orders/{id}/edit-tab', [OrderController::class, 'editTab'])->name('orders.edit.tab');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{orderId}/line-items/bulk-update-status', [OrderController::class, 'bulkUpdateLineItemStatus'])->name('orders.line-items.bulk-update-status');
    Route::post('/orders/bulk-mark-prepared', [OrderController::class, 'bulkMarkOrdersAsPrepared'])->name('orders.bulk-mark-prepared');

    // Rider assignment APIs
    Route::post('/orders/{id}/rider/assign', [\App\Http\Controllers\CRM\OrderRiderController::class, 'assign'])->name('orders.rider.assign');
    Route::get('/orders/{id}/rider/timeline', [\App\Http\Controllers\CRM\OrderRiderController::class, 'timeline'])->name('orders.rider.timeline');
    Route::get('/riders/active', [\App\Http\Controllers\CRM\RiderController::class, 'active'])->name('riders.active');
    
    // Payment method APIs
    Route::post('/orders/api/change-payment-method', [OrderController::class, 'changePaymentMethod'])->name('orders.api.change-payment-method');
    Route::get('/orders/{id}/payment-method/timeline', [OrderController::class, 'getPaymentMethodTimeline'])->name('orders.payment-method.timeline');
    Route::get('/orders/{id}/event-history', [OrderController::class, 'getOrderEventHistory'])->name('orders.event-history');
    Route::get('/orders/{id}/activity-log', [OrderController::class, 'getOrderActivityLog'])->name('orders.activity-log');
    Route::get('/orders/{id}/qurbani-payments', [OrderController::class, 'getQurbaniPayments'])->name('orders.qurbani-payments');
    Route::post('/orders/{id}/qurbani-payments', [OrderController::class, 'addQurbaniPayment'])->name('orders.qurbani-payments.add');
    // Amend non-financial metadata on an existing payment (receiving bank,
    // reference, notes). Deliberately cannot change amount/method/date — those
    // require the void-and-readd flow because of ledger reconciliation.
    Route::put('/orders/{id}/qurbani-payments/{paymentId}', [OrderController::class, 'updateQurbaniPayment'])->name('orders.qurbani-payments.update');
    // Void a previously-added payment. Soft-deletes the row and reverses the
    // paired ledger/account-balance entries so finance stays consistent.
    Route::delete('/orders/{id}/qurbani-payments/{paymentId}', [OrderController::class, 'deleteQurbaniPayment'])->name('orders.qurbani-payments.delete');
    // Shop customer incremental payments. Listing + void reuse the generic
    // (account-agnostic) Qurbani handlers; only the add path differs (online
    // only, posts into the ONLINE bank instead of the Qurbani accounts).
    Route::get('/orders/{id}/shop-payments', [OrderController::class, 'getQurbaniPayments'])->name('orders.shop-payments');
    Route::post('/orders/{id}/shop-payments', [OrderController::class, 'addShopPayment'])->name('orders.shop-payments.add');
    Route::delete('/orders/{id}/shop-payments/{paymentId}', [OrderController::class, 'deleteQurbaniPayment'])->name('orders.shop-payments.delete');
    // Bulk shop payment: split ONE online transfer across several selected shop
    // invoices (oldest-first, remainder on the newest). Literal path — declared
    // here so it can never be captured by an /orders/{id}/... pattern.
    Route::post('/orders/bulk-shop-payments', [OrderController::class, 'bulkShopPayment'])->name('orders.shop-payments.bulk');
    // Save PAID-stamp overrides (sending bank, stamp date, ref mode) without
    // touching any payment row. Used by the "✏️ Edit stamp" popover on
    // invoice.blade.
    Route::post('/orders/{id}/paid-stamp', [OrderController::class, 'updatePaidStamp'])->name('orders.paid-stamp.update');

    // ---------------------------------------------------------------
    // Customer credit ("account balance") — Aug-2026.
    // Regular customers only; shops keep their own incremental payment flow.
    // Literal segments after {orderId} so none of these can be swallowed by an
    // /orders/{id} catch-all.
    // ---------------------------------------------------------------
    Route::get('/orders/{orderId}/credit-offer', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'offerForOrder'])
        ->whereNumber('orderId')->name('orders.credit.offer');
    Route::post('/orders/{orderId}/credit/apply', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'apply'])
        ->whereNumber('orderId')->name('orders.credit.apply');
    Route::post('/orders/{orderId}/credit/remove', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'remove'])
        ->whereNumber('orderId')->name('orders.credit.remove');

    Route::get('/customer-credit/pending', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'pending'])
        ->name('customer-credit.pending');
    Route::get('/customer-credit/{customerId}/summary', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'summary'])
        ->whereNumber('customerId')->name('customer-credit.summary');
    Route::post('/customer-credit/{customerId}/grant', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'grant'])
        ->whereNumber('customerId')->name('customer-credit.grant');
    Route::post('/customer-credit/{customerId}/zero-out', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'zeroOut'])
        ->whereNumber('customerId')->name('customer-credit.zero-out');
    Route::post('/customer-credit/{creditId}/approve', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'approve'])
        ->whereNumber('creditId')->name('customer-credit.approve');
    Route::post('/customer-credit/{creditId}/reject', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'reject'])
        ->whereNumber('creditId')->name('customer-credit.reject');
    // Undo ONE wrong entry, rather than zeroing the whole balance.
    Route::post('/customer-credit/{creditId}/void', [\App\Http\Controllers\CRM\CustomerCreditController::class, 'void'])
        ->whereNumber('creditId')->name('customer-credit.void');
    // Operations import/broadcast endpoints — staff only (rider accounts blocked server-side).
    Route::post('/operations/rider-import', [\App\Http\Controllers\CRM\OperationsController::class, 'importRiderAssignments'])->name('operations.rider-import')->middleware('block.rider');
    Route::post('/operations/attendance-import', [\App\Http\Controllers\CRM\OperationsController::class, 'importAttendance'])->name('operations.attendance-import')->middleware('block.rider');
    Route::post('/operations/history-import', [\App\Http\Controllers\CRM\OperationsController::class, 'importHistoryOrders'])->name('operations.history-import')->middleware('block.rider');
    Route::post('/operations/history-delivery-update', [\App\Http\Controllers\CRM\OperationsController::class, 'updateHistoryDeliveryDates'])->name('operations.history-delivery-update')->middleware('block.rider');
    // App-release broadcast: push "update available" to all active mobile devices (Operations page button)
    Route::post('/operations/notify-app-update', [\App\Http\Controllers\CRM\OperationsController::class, 'notifyAppUpdate'])->name('operations.notify-app-update')->middleware('block.rider');
    
    // Rider profile management
    Route::get('/riders', [\App\Http\Controllers\CRM\RiderProfileController::class, 'index'])->name('riders.index');
    Route::post('/riders', [\App\Http\Controllers\CRM\RiderProfileController::class, 'store'])->name('riders.store');
    Route::get('/riders/{id}', [\App\Http\Controllers\CRM\RiderProfileController::class, 'show'])->name('riders.show');
    // 🏠 Removing a home location is its OWN action — never a side effect of an empty box or
    //    of unticking "company bike" (owner ruling, 10-Sep-2026). Gated on `assign_vehicles`
    //    inside the controller, the same key the Bikes tab and the phone ask.
    Route::post('/riders/{id}/home-pin/clear', [\App\Http\Controllers\CRM\RiderProfileController::class, 'destroyHomePin'])->where('id', '[0-9]+')->name('riders.home-pin.clear');
    /**
     * ⚰ RETIRED 7-Sep-2026 — `POST /riders/shift` is gone. It was the legacy door: it wrote
     *   raw `t_ops_rider_profile.shift_start/shift_end` and had NO permission check at all,
     *   so any logged-in user could rewrite anyone's hours. Its only caller was a modal on
     *   the attendance page that nothing ever opened.
     *
     * ⭐ ONE ENGINE (owner ruling): every shift change goes through `Ops\ShiftController`,
     *   which is where the ladder, the own-shift rule, the allowed-shift lists and the
     *   approval queue live. A second door meant a second set of rules; this one had none.
     */

    // Attendance (admin/manager view)
    Route::get('/attendance', [\App\Http\Controllers\CRM\AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/reports', function() { return view('pages.attendance.reports'); })->name('attendance.reports');
    Route::get('/attendance/data', [\App\Http\Controllers\CRM\AttendanceController::class, 'data'])->name('attendance.data');
    Route::post('/attendance', [\App\Http\Controllers\CRM\AttendanceController::class, 'store'])->name('attendance.store');
    Route::post('/attendance/apply-leave', [\App\Http\Controllers\CRM\AttendanceController::class, 'applyLeave'])->middleware('block.rider')->name('attendance.apply-leave');
    Route::get('/attendance/pending-leaves', [\App\Http\Controllers\CRM\AttendanceController::class, 'pendingLeaves'])->name('attendance.pending-leaves');
    Route::post('/attendance/leave-request/{id}/approve', [\App\Http\Controllers\CRM\AttendanceController::class, 'approveLeaveRequest'])->middleware('block.rider')->name('attendance.leave-approve');
    Route::post('/attendance/leave-request/{id}/reject', [\App\Http\Controllers\CRM\AttendanceController::class, 'rejectLeaveRequest'])->middleware('block.rider')->name('attendance.leave-reject');
    Route::post('/attendance/leave-request/{id}/undo', [\App\Http\Controllers\CRM\AttendanceController::class, 'undoLeaveApproval'])->middleware('block.rider')->name('attendance.leave-undo');
    Route::post('/attendance/grant-leave', [\App\Http\Controllers\CRM\AttendanceController::class, 'grantLeave'])->middleware('block.rider')->name('attendance.grant-leave');
    Route::get('/attendance/leave-balance', [\App\Http\Controllers\CRM\AttendanceController::class, 'leaveBalance'])->name('attendance.leave-balance');
    Route::get('/attendance/approved-leaves', [\App\Http\Controllers\CRM\AttendanceController::class, 'approvedLeaves'])->middleware('block.rider')->name('attendance.approved-leaves');
    Route::post('/attendance/toggle-day-tag', [\App\Http\Controllers\CRM\AttendanceController::class, 'toggleDayTag'])->middleware('block.rider')->name('attendance.toggle-day-tag');
    Route::post('/attendance/day-tag-range', [\App\Http\Controllers\CRM\AttendanceController::class, 'setDayTagRange'])->middleware('block.rider')->name('attendance.day-tag-range');
    Route::post('/attendance/update-meter', [\App\Http\Controllers\CRM\AttendanceController::class, 'updateMeterValues'])->middleware('block.rider')->name('attendance.update-meter');
    Route::post('/attendance/home-journey/unlock', [\App\Http\Controllers\CRM\AttendanceController::class, 'homeJourneyUnlock'])->middleware('block.rider')->name('attendance.home-unlock');
    Route::post('/attendance/home-journey/enter-meter', [\App\Http\Controllers\CRM\AttendanceController::class, 'homeMeterManagerEntry'])->middleware('block.rider')->name('attendance.home-enter-meter');
    Route::post('/attendance/checkout-unlock', [\App\Http\Controllers\CRM\AttendanceController::class, 'checkoutUnlock'])->middleware('block.rider')->name('attendance.checkout-unlock');
    // Undo a checkout — put a rider who already finished back ON DUTY (extra order came in).
    // Clears the whole checkout bundle, not just logout_time; audited in t_sys_audit_log.
    Route::post('/attendance/undo-checkout', [\App\Http\Controllers\CRM\AttendanceController::class, 'undoCheckout'])->middleware('block.rider')->name('attendance.undo-checkout');
    Route::post('/attendance/checkin-unlock', [\App\Http\Controllers\CRM\AttendanceController::class, 'checkinUnlock'])->middleware('block.rider')->name('attendance.checkin-unlock'); // U5 morning-lock valve
    Route::get('/attendance/home-alerts', [\App\Http\Controllers\CRM\AttendanceController::class, 'homeAlerts'])->middleware('block.rider')->name('attendance.home-alerts');
    Route::post('/attendance/home-alerts/dismiss', [\App\Http\Controllers\CRM\AttendanceController::class, 'dismissHomeAlert'])->middleware('block.rider')->name('attendance.home-alert-dismiss');
    Route::get('/attendance/checkout-stuck-alerts', [\App\Http\Controllers\CRM\AttendanceController::class, 'checkoutStuckAlerts'])->middleware('block.rider')->name('attendance.checkout-stuck-alerts');
    Route::post('/attendance/checkout-stuck-alerts/dismiss', [\App\Http\Controllers\CRM\AttendanceController::class, 'dismissCheckoutStuckAlert'])->middleware('block.rider')->name('attendance.checkout-stuck-dismiss');
    Route::get('/attendance/summary', [\App\Http\Controllers\CRM\AttendanceController::class, 'summary'])->name('attendance.summary');
    Route::get('/attendance/monthly-report', [\App\Http\Controllers\CRM\AttendanceController::class, 'monthlyReport'])->name('attendance.monthly-report');
    Route::get('/attendance/employee-details', [\App\Http\Controllers\CRM\AttendanceController::class, 'employeeDetails'])->name('attendance.employee-details');
    Route::get('/attendance/date-breakdown', [\App\Http\Controllers\CRM\AttendanceController::class, 'dateBreakdown'])->name('attendance.date-breakdown');
    Route::get('/attendance/gps-audit', [\App\Http\Controllers\CRM\AttendanceController::class, 'gpsReadingsAudit'])->name('attendance.gps-audit');
    Route::get('/attendance/users-visibility', [\App\Http\Controllers\CRM\AttendanceController::class, 'getUsersVisibility'])->name('attendance.users-visibility');
    Route::post('/attendance/update-visibility', [\App\Http\Controllers\CRM\AttendanceController::class, 'updateUserVisibility'])->name('attendance.update-visibility');
    Route::post('/attendance/update-salary-visibility', [\App\Http\Controllers\CRM\AttendanceController::class, 'updateSalaryVisibility'])->name('attendance.update-salary-visibility');
    Route::post('/attendance/update-delivery-rider', [\App\Http\Controllers\CRM\AttendanceController::class, 'updateDeliveryRider'])->name('attendance.update-delivery-rider');
    // Attendance policy settings (year cycle + meter thresholds)
    Route::get('/attendance/settings', [\App\Http\Controllers\CRM\AttendanceController::class, 'getAttendanceSettings'])->name('attendance.settings.get');
    Route::post('/attendance/settings', [\App\Http\Controllers\CRM\AttendanceController::class, 'saveAttendanceSettings'])->name('attendance.settings.save');

    // Company Locations Management (for attendance tracking)
    Route::get('/attendance/locations', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'index'])->name('attendance.locations');
    Route::post('/attendance/settings/require-location', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'setRequireLocation'])->name('attendance.require-location');
    Route::get('/attendance/locations/data', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'getLocations'])->name('attendance.locations.data');
    Route::post('/attendance/locations', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'store'])->name('attendance.locations.store');
    Route::put('/attendance/locations/{id}', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'update'])->name('attendance.locations.update');
    Route::delete('/attendance/locations/{id}', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'destroy'])->name('attendance.locations.destroy');
    Route::get('/attendance/locations/{id}/users', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'getLocationUsers'])->name('attendance.locations.users');
    Route::get('/attendance/locations/available-users', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'getAvailableUsers'])->name('attendance.locations.available-users');
    Route::post('/attendance/locations/{id}/assign-users', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'assignUsers'])->name('attendance.locations.assign-users');
    Route::delete('/attendance/locations/assignments/{id}', [\App\Http\Controllers\CRM\CompanyLocationsController::class, 'removeUserAssignment'])->name('attendance.locations.remove-assignment');

    // Fuel Rate Groups Management
    Route::get('/attendance/fuel-rate-groups', [\App\Http\Controllers\CRM\AttendanceController::class, 'getFuelRateGroups'])->name('attendance.fuel-rate-groups');
    Route::post('/attendance/fuel-rate-groups', [\App\Http\Controllers\CRM\AttendanceController::class, 'saveFuelRateGroups'])->name('attendance.fuel-rate-groups.save');

    // Delivery Regions Management
    Route::prefix('regions')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'index'])->name('regions.index');
        Route::get('/data', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRegions'])->name('regions.data');
        Route::post('/save', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRegion'])->name('regions.save');
        Route::get('/areas', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getAreas'])->name('regions.areas');
        Route::post('/areas/save', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveArea'])->name('regions.areas.save');
        Route::delete('/areas/{id}', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'deleteArea'])->name('regions.areas.delete');
        Route::get('/riders', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRiderAssignments'])->name('regions.riders');
        Route::get('/riders/active', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getActiveRiders'])->name('regions.riders.active');
        Route::post('/riders/assign', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRiderAssignment'])->name('regions.riders.assign');
        Route::delete('/riders/{id}', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'removeRiderAssignment'])->name('regions.riders.remove');
        Route::post('/batch-detect', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'batchDetect'])->name('regions.batch-detect');
        Route::post('/redetect-all', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'redetectAll'])->name('regions.redetect-all');
        Route::get('/stats', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getStats'])->name('regions.stats');
        Route::get('/customer-cities', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getCustomerCities'])->name('regions.customer-cities');
        Route::get('/list', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRegionsList'])->name('regions.list');
        Route::post('/set-order-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'setOrderRegion'])->name('regions.set-order-region');
        Route::post('/set-customer-region', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'setCustomerRegion'])->name('regions.set-customer-region');
        Route::post('/auto-assign-riders', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'autoAssignRiders'])->name('regions.auto-assign-riders');
        Route::post('/save-polygon', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'saveRegionPolygon'])->name('regions.save-polygon');
        Route::post('/remove-polygon', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'removeRegionPolygon'])->name('regions.remove-polygon');
        Route::get('/suggestions', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'getRegionSuggestions'])->name('regions.suggestions');
        Route::post('/suggestions/apply', [\App\Http\Controllers\CRM\DeliveryRegionController::class, 'applyRegionSuggestions'])->name('regions.suggestions.apply');
    });

    // Open Orders → "Get Customer Locations" (bulk WhatsApp location request).
    // Stateless: recomputes the unverified open list live each time; no tracking table.
    Route::prefix('orders/location-request')->name('orders.location-request.')->group(function () {
        Route::get('/eligible', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'eligible'])->name('eligible');
        Route::post('/send', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'send'])->name('send');
        // Default template + auto-send automation settings (admin).
        Route::get('/settings', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'settings'])->name('settings');
        Route::post('/settings', [\App\Http\Controllers\CRM\OpenOrderLocationController::class, 'saveSettings'])->name('settings.save');
    });

    // Shift Management
    Route::get('/shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'index'])->name('shifts.index');
    Route::get('/shifts/clear-cache', [\App\Http\Controllers\Ops\ShiftController::class, 'clearCache'])->name('shifts.clear-cache');
    Route::get('/shifts/list', [\App\Http\Controllers\Ops\ShiftController::class, 'list'])->name('shifts.list');
    Route::post('/shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{id}', [\App\Http\Controllers\Ops\ShiftController::class, 'update'])->name('shifts.update');
    Route::delete('/shifts/{id}', [\App\Http\Controllers\Ops\ShiftController::class, 'destroy'])->name('shifts.destroy');
    Route::post('/shifts/{id}/set-default', [\App\Http\Controllers\Ops\ShiftController::class, 'setDefault'])->name('shifts.set-default');
    Route::post('/shifts/{id}/set-active', [\App\Http\Controllers\Ops\ShiftController::class, 'setActive'])->name('shifts.set-active');
    Route::get('/shifts/users-with-shifts', [\App\Http\Controllers\Ops\ShiftController::class, 'getUsersWithShifts'])->name('shifts.users-with-shifts');
    Route::post('/shifts/assign', [\App\Http\Controllers\Ops\ShiftController::class, 'assignShiftToUser'])->name('shifts.assign');
    Route::post('/shifts/bulk-assign', [\App\Http\Controllers\Ops\ShiftController::class, 'bulkAssignShift'])->name('shifts.bulk-assign');
    Route::delete('/shifts/remove-assignment', [\App\Http\Controllers\Ops\ShiftController::class, 'removeShiftAssignment'])->name('shifts.remove-assignment');
    Route::post('/shifts/cancel-change', [\App\Http\Controllers\Ops\ShiftController::class, 'cancelShiftChange'])->name('shifts.cancel-change');
    Route::get('/shifts/user-summary', [\App\Http\Controllers\Ops\ShiftController::class, 'userShiftSummary'])->name('shifts.user-summary');
    Route::get('/shifts/history', [\App\Http\Controllers\Ops\ShiftController::class, 'assignmentHistory'])->name('shifts.history');

    // Shift Planner (the consolidated week-grid assignment view)
    Route::get('/shift-planner', [\App\Http\Controllers\Ops\ShiftPlannerController::class, 'index'])->name('shift-planner.index');
    Route::get('/shift-planner/week', [\App\Http\Controllers\Ops\ShiftPlannerController::class, 'weekData'])->name('shift-planner.week');
    Route::post('/shift-planner/update-phone', [\App\Http\Controllers\Ops\ShiftPlannerController::class, 'updatePhone'])->name('shift-planner.update-phone');
    // 👥 Users list — who is on the shared Shift Planner + Attendance roster.
    //    Gated inside the controller to Shabib + Taimur; the WRITE is /attendance/update-visibility.
    Route::get('/shift-planner/users-list', [\App\Http\Controllers\Ops\ShiftPlannerController::class, 'usersList'])->name('shift-planner.users-list');

    /**
     * ⚙ SHIFT RULES (Sep-2026) — who may change whose shift, and the approval queue.
     *
     * ⚠⚠ TWO GATES, both INSIDE the controller (never middleware here, because the phone
     *    calls the same methods through routes/api.php and the two must not drift):
     *      • the settings half needs `manage_shift_rules` — Taimur only, owner ruling 6-Sep;
     *      • approve / decline is answered by LADDER POSITION, so it is deliberately open to
     *        any planner and returns an empty queue to everyone else.
     *
     * ⚠ Own prefix, not `/shifts/...`, so nothing can ever be confused with
     *   `POST /shifts/{id}/set-default` — `{id}` would happily match the word "rules".
     */
    Route::get('/shift-rules', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'index'])->name('shift-rules.index');
    Route::get('/shift-rules/data', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'data'])->name('shift-rules.data');
    Route::post('/shift-rules/ladder', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'saveLadder'])->name('shift-rules.ladder');
    Route::post('/shift-rules/person', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'savePerson'])->name('shift-rules.person');
    Route::post('/shift-rules/person/remove', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'removePerson'])->name('shift-rules.person.remove');
    Route::post('/shift-rules/switch', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'saveSwitch'])->name('shift-rules.switch');
    // The corner banner polls this from the planner, attendance and riders-map pages.
    Route::get('/shift-rules/approvals', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'approvals'])->name('shift-rules.approvals');
    Route::post('/shift-rules/requests/{id}/approve', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'approve'])->name('shift-rules.approve')->where('id', '[0-9]+');
    Route::post('/shift-rules/requests/{id}/decline', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'decline'])->name('shift-rules.decline')->where('id', '[0-9]+');
    Route::post('/shift-rules/requests/{id}/withdraw', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'withdraw'])->name('shift-rules.withdraw')->where('id', '[0-9]+');
    Route::post('/shift-rules/types/{id}/approve', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'approveTemplate'])->name('shift-rules.type-approve')->where('id', '[0-9]+');
    Route::post('/shift-rules/types/{id}/decline', [\App\Http\Controllers\Ops\ShiftRulesController::class, 'declineTemplate'])->name('shift-rules.type-decline')->where('id', '[0-9]+');

    // Holiday Management
    Route::get('/holidays', [\App\Http\Controllers\Ops\HolidayController::class, 'index'])->name('holidays.index');
    Route::get('/holidays/list', [\App\Http\Controllers\Ops\HolidayController::class, 'list'])->name('holidays.list');
    Route::post('/holidays', [\App\Http\Controllers\Ops\HolidayController::class, 'store'])->name('holidays.store');
    Route::delete('/holidays/{id}', [\App\Http\Controllers\Ops\HolidayController::class, 'destroy'])->name('holidays.destroy');
    Route::get('/holidays/upcoming', [\App\Http\Controllers\Ops\HolidayController::class, 'upcoming'])->name('holidays.upcoming');
    Route::get('/holidays/years', [\App\Http\Controllers\Ops\HolidayController::class, 'getYears'])->name('holidays.years');
    Route::get('/users/all', [\App\Http\Controllers\SysAdmin\UserController::class, 'allActive'])->name('users.all');
    Route::post('/orders/{id}/convert', [OrderController::class, 'convertOrder'])->name('orders.convert');
    Route::post('/orders/{id}/ignore', [OrderController::class, 'ignoreOrder'])->name('orders.ignore');
    Route::post('/orders/import-orders', [OrderController::class, 'importOrders'])->name('orders.importOrders');
    
    // Order Status Management Routes
    Route::prefix('order-status')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\OrderStatusController::class, 'index'])->name('order-status.index');
        Route::get('/api/statuses', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getAllStatuses'])->name('order-status.api.statuses');
        Route::get('/api/statistics', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getStatistics'])->name('order-status.api.statistics');
        Route::post('/api/statuses', [\App\Http\Controllers\CRM\OrderStatusController::class, 'store'])->name('order-status.api.store');
        Route::put('/api/statuses/{id}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'update'])->name('order-status.api.update');
        Route::delete('/api/statuses/{id}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'destroy'])->name('order-status.api.destroy');
        Route::post('/api/change-status', [\App\Http\Controllers\CRM\OrderStatusController::class, 'changeOrderStatus'])->name('order-status.api.change');
        Route::post('/api/bulk-change', [\App\Http\Controllers\CRM\OrderStatusController::class, 'bulkChangeStatus'])->name('order-status.api.bulk-change');
        Route::get('/api/orders/{id}/history', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrderHistory'])->name('order-status.api.history');
        Route::put('/api/history/{historyId}/update-timestamp', [\App\Http\Controllers\CRM\OrderStatusController::class, 'updateHistoryTimestamp'])->name('order-status.api.update-timestamp');
        Route::get('/api/orders/{id}/timeline', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrderTimeline'])->name('order-status.api.timeline');
        Route::get('/api/statuses/{id}/transitions', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getAvailableTransitions'])->name('order-status.api.transitions');
        Route::put('/api/statuses/{id}/transitions', [\App\Http\Controllers\CRM\OrderStatusController::class, 'updateTransitions'])->name('order-status.api.update-transitions');
        Route::post('/api/reorder', [\App\Http\Controllers\CRM\OrderStatusController::class, 'reorderStatuses'])->name('order-status.api.reorder');
        Route::get('/api/orders', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getOrdersByStatus'])->name('order-status.api.orders');
        Route::get('/api/roles', [\App\Http\Controllers\CRM\OrderStatusController::class, 'getAvailableRoles'])->name('order-status.api.roles');
        Route::get('/history', [\App\Http\Controllers\CRM\OrderStatusController::class, 'historyIndex'])->name('order-status.history.index');
        Route::get('/history/{orderId}', [\App\Http\Controllers\CRM\OrderStatusController::class, 'orderHistory'])->name('order-status.history.order');
    });

    // Qurbani Settings Routes
    Route::prefix('qurbani-settings')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'index'])->name('qurbani-settings.index');
        Route::get('/api/options', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'getOptions'])->name('qurbani-settings.api.options');
        Route::post('/api/options', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'storeOption'])->name('qurbani-settings.api.store');
        Route::put('/api/options/{id}', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateOption'])->name('qurbani-settings.api.update');
        Route::delete('/api/options/{id}', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'deleteOption'])->name('qurbani-settings.api.delete');
        Route::post('/api/reorder', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'reorderOptions'])->name('qurbani-settings.api.reorder');
        Route::post('/api/shipping-price', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateShippingPrice'])->name('qurbani-settings.api.shipping-price');
        Route::post('/api/default-payment-method', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateDefaultPaymentMethod'])->name('qurbani-settings.api.default-payment-method');
        Route::post('/api/cancellation-code', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateCancellationCode'])->name('qurbani-settings.api.cancellation-code');
        Route::post('/api/hidden-categories', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateHiddenCategories'])->name('qurbani-settings.api.hidden-categories');
        // May-2026 — Qurbani rider whitelist (mobile rider pickers).
        Route::post('/api/qurbani-riders', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateQurbaniRiders'])->name('qurbani-settings.api.qurbani-riders');
        // May-2026 — Per-rider region + contact saved separately so
        // adding/removing from the whitelist doesn't erase the meta.
        // Region drives the auto-sort of the mobile rider picker by
        // bulk-selection region; contact is reserved for the next-phase
        // OFD WhatsApp template.
        Route::post('/api/qurbani-rider-meta', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateQurbaniRiderMeta'])->name('qurbani-settings.api.qurbani-rider-meta');
        // May-2026 — UI-driven verified-coords backfill (replaces the
        // SSH-only artisan command for non-CLI admins).
        Route::post('/api/backfill-verified-coords', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'backfillVerifiedCoords'])->name('qurbani-settings.api.backfill-verified-coords');
        // Qurbani Operations Base location — used as origin fallback +
        // return-to-base ETA endpoint (May-2026 plan). Save name/lat/lng
        // as ConfigModel keys; pass all-empty to clear.
        Route::post('/api/base-location', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateBaseLocation'])->name('qurbani-settings.api.base-location');
        // Rider-side ETA auto-refresh cadence (Phase D).
        Route::post('/api/eta-refresh', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateEtaRefresh'])->name('qurbani-settings.api.eta-refresh');
        // Phase 3 (May-2026) — Qurbani auto-WhatsApp settings.
        Route::get('/api/wa-auto',  [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'getWaAuto'])->name('qurbani-settings.api.wa-auto.get');
        Route::post('/api/wa-auto', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateWaAuto'])->name('qurbani-settings.api.wa-auto');
        // May-2026 — diagnostic + manual-tick endpoints so an admin can
        // answer "why isn't my slaughter message firing?" without SSH.
        // diagnose returns a structured per-candidate explanation;
        // run-now fires the worker immediately and returns the log
        // rows it created. See QurbaniSettingsController for the
        // full payload shape.
        Route::post('/api/wa-auto/diagnose', [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'diagnoseWaAuto'])->name('qurbani-settings.api.wa-auto.diagnose');
        Route::post('/api/wa-auto/run-now',  [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'runWaAutoNow'])->name('qurbani-settings.api.wa-auto.run-now');
        // Phase 4 (May-2026) — slot start/end minute editing + bulk
        // auto-detect button on the slots section of the settings page.
        // Cascades writes down to t_crm_prod_order_line_item.
        Route::post('/api/slots/{id}/minutes',     [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'updateSlotMinutes'])->name('qurbani-settings.api.slot-minutes');
        Route::post('/api/slots/auto-detect-all',  [\App\Http\Controllers\CRM\QurbaniSettingsController::class, 'bulkAutoDetectSlotMinutes'])->name('qurbani-settings.api.slots-auto-detect-all');
    });

    // Qurbani Web Pages (orders, dashboard)
    Route::prefix('qurbani')->group(function () {
        // -----------------------------------------------------------
        // QURBANI ORDERS — region-wise item-level dispatch view
        // (May-2026). The page that mirrors the mobile app's
        // QurbaniOpenOrdersScreen and drives the box-label printer.
        // -----------------------------------------------------------
        Route::get('/orders', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'orders'])->name('qurbani.orders');
        Route::get('/api/orders-items', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getOrderItems'])->name('qurbani.api.orders-items');
        Route::get('/api/box-labels', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getBoxLabels'])->name('qurbani.api.box-labels');
        Route::post('/api/box-print/mark', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'markBoxesPrinted'])->name('qurbani.api.box-print.mark');
        Route::post('/api/box-print/unmark', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'unmarkBoxesPrinted'])->name('qurbani.api.box-print.unmark');
        // Inline per-row actions on the new orders dispatch page.
        Route::post('/api/items/{id}/status', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'updateItemStatus'])->name('qurbani.api.items.status');
        Route::post('/api/items/{id}/rider', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'assignItemRider'])->name('qurbani.api.items.rider');
        // Phase C (May-2026) — dispatch-map endpoint, returns rider GPS
        // + bundle pins + base for the per-rider Map modal on the
        // Qurbani Orders web page. Mirrors the rider-side endpoint.
        Route::get('/api/riders/{riderId}/dispatch-map', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniDispatchMap'])->name('qurbani.api.riders.dispatch-map');
        // Phase 2 (May-2026) — order timeline endpoint, returns status +
        // dispatch + current ETA + delay alert + today's WhatsApp
        // activity for a single Qurbani line item. Mirrors the mobile
        // endpoint. Manager-only (access_qurbani_mode).
        Route::get('/api/line-items/{lineItemId}/timeline', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniOrderTimeline'])->name('qurbani.api.line-items.timeline');

        // -----------------------------------------------------------
        // QURBANI RIDERS — Phase 1 (May-2026). Read-only web mirror
        // of the mobile manager dispatch view (QurbaniRidersScreen +
        // QurbaniRiderRouteScreen). The list page lands the manager
        // on a grid of riders; clicking one opens the route detail
        // page with bundle sections + ETAs + dispatch map.
        //
        // Phase 1 is INFORMATIONAL only — Dispatch / Cancel /
        // Auto Route / Edit Route / Live Tracking still happen on
        // the mobile so we don't duplicate destructive action paths
        // before the read-only shape is validated.
        //
        // Both API endpoints are thin aliases to the same
        // RiderController methods the mobile uses. Permission gates
        // inside those methods (hasMobilePermission('access_qurbani_mode'))
        // work identically on web session auth. The dispatch-map +
        // timeline endpoints already exist further up so we don't
        // add aliases for them.
        // -----------------------------------------------------------
        Route::get('/riders', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'ridersIndex'])
            ->name('qurbani.riders.index');
        Route::get('/riders/{riderId}', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'riderRouteView'])
            ->whereNumber('riderId')
            ->name('qurbani.riders.route');
        Route::get('/api/riders', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniRidersSummary'])
            ->name('qurbani.api.riders');
        Route::get('/api/riders/{riderId}/route', [\App\Http\Controllers\API\RiderController::class, 'getQurbaniRiderRoute'])
            ->whereNumber('riderId')
            ->name('qurbani.api.riders.route');

        // -----------------------------------------------------------
        // QURBANI INVOICES — invoice-level page (formerly /qurbani/orders).
        // Renamed in May-2026 so /orders can host the new dispatch view.
        // -----------------------------------------------------------
        Route::get('/invoices', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'invoices'])->name('qurbani.invoices');
        Route::get('/api/invoices', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getInvoices'])->name('qurbani.api.invoices');
        // Back-compat alias: the previous URL still works for any
        // bookmarks / reports that point at /qurbani/api/orders.
        Route::get('/api/orders', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getInvoices'])->name('qurbani.api.orders');

        // -----------------------------------------------------------
        // QURBANI PERFORMANCE — Phase 5 (May-2026). Web-only
        // operational snapshot dashboard with day-state machine and
        // clickable KPI drilldowns. Lives under /qurbani/performance.
        // -----------------------------------------------------------
        Route::get('/performance', [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'index'])->name('qurbani.performance');
        Route::get('/api/performance/summary',  [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'summary'])->name('qurbani.api.performance.summary');
        Route::get('/api/performance/drill',    [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'drill'])->name('qurbani.api.performance.drill');
        // May-2026 — Slot SLA buckets table (above the per-slot point-
        // in-time rollup). Returns per-slot counts by signed time-
        // delta vs slot end for one of: delivered / out_for_delivery
        // / slaughtered. Delivery-only (self-collection excluded).
        Route::get('/api/performance/slot-sla', [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'slotSla'])->name('qurbani.api.performance.slot-sla');
        Route::post('/api/performance/day-state', [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'setDayState'])->name('qurbani.api.performance.day-state');
        // May-2026 — CS Manager quick-action: send slaughter / OFD
        // WhatsApp template for one line item on demand. Bypasses
        // the worker's time-delay gate; respects every other gate.
        Route::post('/api/performance/send-wa-now', [\App\Http\Controllers\CRM\QurbaniPerformanceController::class, 'sendWaNow'])->name('qurbani.api.performance.send-wa-now');

        Route::get('/api/dashboard', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getDashboardData'])->name('qurbani.api.dashboard');
        Route::get('/api/payment-summary', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getPaymentAccountSummary'])->name('qurbani.api.payment-summary');
        Route::get('/api/payment-details', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getPaymentAccountDetails'])->name('qurbani.api.payment-details');
        Route::get('/api/order-stats', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'getOrderStats'])->name('qurbani.api.order-stats');
        // Apr-2026: soft-cap target quantities per (delivery_type, day, category)
        // and optionally per (slot, region) on the booked-summary card.
        Route::post('/api/targets', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'saveQurbaniTargets'])->name('qurbani.api.save-targets');
        Route::post('/api/toggle', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'toggleQurbaniMode'])->name('qurbani.api.toggle');
        Route::post('/api/toggle-rider-delivered', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'toggleRiderDelivered'])->name('qurbani.api.toggle-rider-delivered');
        Route::post('/api/toggle-delete', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'toggleDeleteEnabled'])->name('qurbani.api.toggle-delete');
        Route::delete('/api/orders/{id}', [\App\Http\Controllers\CRM\QurbaniWebController::class, 'deleteOrder'])->name('qurbani.api.delete-order');

        // -----------------------------------------------------------
        // QURBANI LOCATION REQUEST — Phase 6 (May-2026).
        // WhatsApp template-driven location collection. Backs the
        // "Request Location" toolbar button + per-card button on the
        // Orders page, the Reviewer drawer (right sidebar), and the
        // mobile per-card request button. See
        // QurbaniLocationRequestController for the endpoint map.
        // -----------------------------------------------------------
        Route::prefix('api/loc-request')->name('qurbani.api.loc-request.')->group(function () {
            Route::get('/eligible',              [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'eligible'])->name('eligible');
            Route::post('/send-bulk',            [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'sendBulk'])->name('send-bulk');
            Route::post('/bulk/{batchId}/start', [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'startBatch'])->name('bulk-start');
            Route::post('/send-one',             [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'sendOne'])->name('send-one');
            Route::get('/summary',               [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'summary'])->name('summary');
            Route::get('/batch/{batchId}',       [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'batchDetail'])->name('batch-detail');
            Route::get('/pending-review',        [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'pendingReview'])->name('pending-review');
            Route::post('/statuses',             [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'statuses'])->name('statuses');
            Route::post('/save/{id}',            [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'save'])->name('save');
            Route::post('/save-all',             [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'saveAll'])->name('save-all');
            Route::post('/dismiss/{id}',         [\App\Http\Controllers\CRM\QurbaniLocationRequestController::class, 'dismiss'])->name('dismiss');
        });
    });

    // Bulk Status Update Routes (Admin only) — mass LIVE order status change; staff only.
    Route::prefix('admin')->middleware('block.rider')->group(function () {
        Route::get('/bulk-status-update', [\App\Http\Controllers\CRM\BulkStatusUpdateController::class, 'showUploadForm'])->name('admin.bulk-status-update');
        Route::post('/bulk-status-update', [\App\Http\Controllers\CRM\BulkStatusUpdateController::class, 'processUpload'])->name('admin.bulk-status-update.process');
    });
    
    // API endpoints for products
    Route::get('/api/products/search', [\App\Http\Controllers\CRM\ProductController::class, 'search'])->name('products.search');
    
    // API endpoints for customers
    Route::get('/api/customers/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search'])->name('customers.search');
    
    // WhatsApp Messages Routes
    Route::prefix('messages')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'index'])->name('messages.index');
        Route::get('/conversations', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getConversations'])->name('messages.conversations');
        Route::get('/conversations/{id}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getMessages'])->name('messages.messages');
        Route::post('/conversations/{id}/send', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'sendMessage'])->name('messages.send');
        Route::post('/conversations/{id}/mark-read', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'markRead'])->name('messages.markRead');
        Route::post('/conversations/{id}/mark-unread', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'markUnread'])->name('messages.markUnread');
        // Apr-2026: Bulk Mark-All-Read — super-reader only (Taimur role).
        Route::post('/mark-all-read', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'markAllRead'])->name('messages.markAllRead');
        // Labels (Phase 1)
        Route::get('/labels', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getLabels'])->name('messages.getLabels');
        Route::post('/labels', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'createLabel'])->name('messages.createLabel');
        Route::put('/labels/{id}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'updateLabel'])->name('messages.updateLabel');
        Route::delete('/labels/{id}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'deleteLabel'])->name('messages.deleteLabel');
        Route::post('/conversations/{id}/labels', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'applyLabel'])->name('messages.applyLabel');
        Route::delete('/conversations/{id}/labels/{labelId}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'removeLabel'])->name('messages.removeLabel');
        // Labels — Phase 2 (user mentions)
        Route::get('/label-users',    [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getLabelUsers'])->name('messages.getLabelUsers');
        Route::get('/mentions-count', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getMentionsCount'])->name('messages.getMentionsCount');
        Route::post('/send-template', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'sendTemplate'])->name('messages.sendTemplate');
        // Marketing-template dedup pre-flight + pinned indicator endpoints.
        // See add_marketing_dedup_apr2026.sql migration.
        Route::post('/template-recent-send-check', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'templateRecentSendCheck'])->name('messages.templateRecentSendCheck');
        Route::get('/conversations/{id}/recent-marketing-templates', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'recentMarketingTemplates'])->name('messages.recentMarketingTemplates');
        Route::get('/templates', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getTemplates'])->name('messages.templates');
        Route::post('/templates', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'storeTemplate'])->name('messages.storeTemplate');
        Route::put('/templates/{id}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'updateTemplate'])->name('messages.updateTemplate');
        Route::delete('/templates/{id}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'deleteTemplate'])->name('messages.deleteTemplate');
        Route::get('/unread-count', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getUnreadCount'])->name('messages.unreadCount');
        Route::get('/customer-orders/{customerId}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getCustomerOrders'])->name('messages.customerOrders');
        Route::get('/invoice-image/{orderId}', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getInvoiceImageUrl'])->name('messages.invoiceImage');
        Route::post('/upload-invoice-image', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'uploadInvoiceImage'])->name('messages.uploadInvoiceImage');
        Route::post('/send-invoice', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'sendInvoice'])->name('messages.sendInvoice');
        // Aug-2026: manual image attachment from the web composer. Thin
        // delegate to the API controller's sendImageMessage (already used by
        // the NF Messages APK) so validation, Meta upload, storage layout and
        // the outbound row shape stay identical across web and mobile.
        Route::post('/conversations/{id}/send-image', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'sendImageAttachment'])->name('messages.sendImage');

        // Qurbani tab — feature toggle, keywords, active year, rescan.
        Route::get('/qurbani-settings', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getQurbaniSettings'])->name('messages.qurbaniSettings');
        Route::post('/qurbani-settings', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'updateQurbaniSettings'])->name('messages.updateQurbaniSettings');
        Route::post('/qurbani-rescan', [\App\Http\Controllers\Web\WhatsAppWebController::class, 'rescanQurbani'])->name('messages.qurbaniRescan');

        // Auto-reply (out-of-hours / day-off / custom rules) — Apr 2026.
        // All four endpoints are gated on the manage_wa_auto_reply
        // permission inside the controller. See add_wa_auto_reply_apr2026.sql.
        Route::get('/auto-reply',                 [\App\Http\Controllers\Web\WhatsAppWebController::class, 'getAutoReplySettings'])->name('messages.autoReply.get');
        Route::post('/auto-reply/toggle',         [\App\Http\Controllers\Web\WhatsAppWebController::class, 'toggleAutoReply'])->name('messages.autoReply.toggle');
        Route::post('/auto-reply/rules',          [\App\Http\Controllers\Web\WhatsAppWebController::class, 'saveAutoReplyRule'])->name('messages.autoReply.create');
        Route::post('/auto-reply/rules/{id}',     [\App\Http\Controllers\Web\WhatsAppWebController::class, 'saveAutoReplyRule'])->name('messages.autoReply.update');
        Route::delete('/auto-reply/rules/{id}',   [\App\Http\Controllers\Web\WhatsAppWebController::class, 'deleteAutoReplyRule'])->name('messages.autoReply.delete');

        // Event-triggered automations (order / invoice) — Jun 2026, Phase 1.
        // Settings only; the dispatcher (WhatsAppAutomationService) does the
        // sending from order/invoice seams wired in Phases 2 & 3. Gated on
        // manage_wa_auto_reply inside the controller. See create_wa_automations_jun2026.sql.
        Route::get('/automations',              [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'index'])->name('messages.automations.get');
        Route::post('/automations/toggle',      [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'toggle'])->name('messages.automations.toggle');
        Route::post('/automations/test-phone',  [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'saveTestPhone'])->name('messages.automations.testPhone');
        Route::post('/automations/rules/{key}', [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'saveRule'])->name('messages.automations.saveRule');
        // Enabled-only toggle (does NOT touch template/config) — used by the
        // Open Orders page banner for seasonal automations. Same gate.
        Route::post('/automations/rules/{key}/enabled', [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'setRuleEnabled'])->name('messages.automations.setRuleEnabled');
        Route::get('/automations/log',          [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'log'])->name('messages.automations.log');
        // Non-gated: lets the Send-Invoice dialogs pre-fill online/cash template by payment method.
        Route::get('/automations/invoice-template-map', [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'invoiceTemplateMap'])->name('messages.automations.invoiceTemplateMap');
        // Non-gated: full Send-Invoice plan for one order (template + body vars + ETA).
        Route::get('/automations/invoice-send-plan/{orderId}', [\App\Http\Controllers\Web\WhatsAppAutomationController::class, 'invoiceSendPlan'])->name('messages.automations.invoiceSendPlan');
    });

    // Campaign Management Routes
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\CampaignWebController::class, 'index'])->name('campaigns.index');
        Route::get('/list', [\App\Http\Controllers\Web\CampaignWebController::class, 'getCampaigns']);
        Route::get('/templates', [\App\Http\Controllers\Web\CampaignWebController::class, 'getTemplates']);
        Route::get('/cities', [\App\Http\Controllers\Web\CampaignWebController::class, 'getCities']);
        Route::get('/qurbani-years', [\App\Http\Controllers\Web\CampaignWebController::class, 'getQurbaniYears']);
        Route::get('/products', [\App\Http\Controllers\Web\CampaignWebController::class, 'getProducts']);
        Route::get('/quota', [\App\Http\Controllers\Web\CampaignWebController::class, 'quota']);
        // Landing view: allowance, live sends, per-template results, attention list.
        Route::get('/overview', [\App\Http\Controllers\Web\CampaignWebController::class, 'overview']);
        // Results grouped by template (per-campaign + combined unique). MUST stay
        // above the /{id} route or 'by-template' resolves as a campaign id.
        Route::get('/by-template', [\App\Http\Controllers\Web\CampaignWebController::class, 'byTemplate']);
        Route::post('/preview', [\App\Http\Controllers\Web\CampaignWebController::class, 'preview']);
        Route::post('/create', [\App\Http\Controllers\Web\CampaignWebController::class, 'create']);
        Route::get('/{id}', [\App\Http\Controllers\Web\CampaignWebController::class, 'detail']);
        Route::post('/{id}/add-customers', [\App\Http\Controllers\Web\CampaignWebController::class, 'addCustomers']);
        // Send: one bounded slice per call. The browser re-calls while the
        // response says it ran out of time budget.
        Route::post('/{id}/send', [\App\Http\Controllers\Web\CampaignWebController::class, 'send']);
        Route::post('/{id}/send-background', [\App\Http\Controllers\Web\CampaignWebController::class, 'sendBackground']);
        Route::post('/{id}/send-pause', [\App\Http\Controllers\Web\CampaignWebController::class, 'sendPause']);
        Route::get('/{id}/send-status', [\App\Http\Controllers\Web\CampaignWebController::class, 'sendStatus']);
        // Aug-2026 — put never-delivered recipients back in Pending so they can
        // be resent (Meta's marketing frequency cap drops some deliveries; the
        // customer never received the message, so a resend is a retry, not spam).
        Route::post('/{id}/requeue-undelivered', [\App\Http\Controllers\Web\CampaignWebController::class, 'requeueUndelivered']);
        // Legacy alias — the old chunked client posted here. Kept so a stale
        // browser tab mid-send doesn't 404 after the upload.
        Route::post('/{id}/send-bulk', [\App\Http\Controllers\Web\CampaignWebController::class, 'send']);
        // Change the campaign's SEND ORDER (also re-orders the list). This is
        // what "send to the top 100 by spend" rides on — the sender reads the
        // stored sort.
        Route::post('/{id}/sort', [\App\Http\Controllers\Web\CampaignWebController::class, 'setSort']);
        Route::post('/{id}/refresh-dedup', [\App\Http\Controllers\Web\CampaignWebController::class, 'refreshDedup']);
        Route::post('/{id}/end', [\App\Http\Controllers\Web\CampaignWebController::class, 'end']);
        Route::post('/{id}/customers/{customerId}/skip', [\App\Http\Controllers\Web\CampaignWebController::class, 'skip']);
        Route::get('/{id}/stats', [\App\Http\Controllers\Web\CampaignWebController::class, 'stats']);
    });

    // Customer Management Routes
    Route::prefix('customers')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CustomerController::class, 'index'])->name('customers.index');
        Route::get('/search', [\App\Http\Controllers\CRM\CustomerController::class, 'search'])->name('customers.search.alt');
        Route::get('/filter', [\App\Http\Controllers\CRM\CustomerController::class, 'filter'])->name('customers.filter');

        // ---------------------------------------------------------------
        // 💰 Customer Balances — the audit screen for the account-balance
        // bucket (Sep-2026). Read-only: every correction offered on the page
        // posts to the existing /customer-credit/* endpoints.
        // ⚠ MUST stay above /{id} or "balances" is read as a customer id.
        // Riders are blocked server-side — this is money, not delivery work.
        // ---------------------------------------------------------------
        Route::middleware('block.rider')->group(function () {
            Route::get('/balances', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'index'])->name('customers.balances');
            Route::get('/balances/data', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'balances'])->name('customers.balances.data');
            Route::get('/balances/activity', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'activity'])->name('customers.balances.activity');
            Route::get('/balances/daily', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'daily'])->name('customers.balances.daily');
            Route::get('/balances/overview', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'overview'])->name('customers.balances.overview');
            Route::get('/balances/export', [\App\Http\Controllers\CRM\CustomerCreditReportController::class, 'export'])->name('customers.balances.export');
        });

        // Geocoding routes (must be before {id} to avoid conflict)
        Route::get('/geocode-stats', [\App\Http\Controllers\CRM\CustomerController::class, 'geocodeStats'])->name('customers.geocode-stats');
        Route::post('/batch-geocode', [\App\Http\Controllers\CRM\CustomerController::class, 'batchGeocode'])->name('customers.batch-geocode');
        // Merge routes (must be before {id} to avoid conflict)
        Route::get('/find-duplicates', [\App\Http\Controllers\CRM\CustomerController::class, 'findDuplicates'])->name('customers.find-duplicates');
        Route::get('/search-for-merge', [\App\Http\Controllers\CRM\CustomerController::class, 'searchForMerge'])->name('customers.search-for-merge');
        Route::post('/merge', [\App\Http\Controllers\CRM\CustomerController::class, 'mergeCustomers'])->name('customers.merge');
        // Aug-2026 — customers sitting on an unused location pin (orders-list badge).
        // MUST stay above /{id} or "pin-replies-pending" is read as a customer id.
        Route::get('/pin-replies-pending', [\App\Http\Controllers\CRM\CustomerController::class, 'pinRepliesPending'])->name('customers.pinRepliesPending');
        Route::post('/upload-promo-image', [\App\Http\Controllers\CRM\CustomerController::class, 'uploadPromoImage'])->name('customers.upload-promo-image');
        Route::post('/delete-promo-image', [\App\Http\Controllers\CRM\CustomerController::class, 'deletePromoImage'])->name('customers.delete-promo-image');
        Route::get('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'show'])->name('customers.show');
        Route::get('/{id}/orders', [\App\Http\Controllers\CRM\CustomerController::class, 'orders'])->name('customers.orders');
        Route::get('/history-order/{historyOrderId}', [\App\Http\Controllers\CRM\CustomerController::class, 'historyOrderDetails'])->name('customers.history-order');
        Route::put('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'update'])->name('customers.update');
        Route::post('/{id}/notes', [\App\Http\Controllers\CRM\CustomerController::class, 'addNote'])->name('customers.addNote');
        Route::post('/{id}/set-verified-location', [\App\Http\Controllers\CRM\CustomerController::class, 'setVerifiedLocation'])->name('customers.setVerifiedLocation');
        // Aug-2026 — pin history ("who set this location, and how?").
        Route::get('/{id}/location-history', [\App\Http\Controllers\CRM\CustomerController::class, 'locationHistory'])->name('customers.locationHistory');
        // Verified-pin lock: grant / cancel a rider unlock window (Jul-2026)
        Route::post('/{id}/unlock-verified-pin', [\App\Http\Controllers\CRM\CustomerController::class, 'unlockVerifiedPin'])->name('customers.unlockVerifiedPin');
        Route::post('/{id}/relock-verified-pin', [\App\Http\Controllers\CRM\CustomerController::class, 'relockVerifiedPin'])->name('customers.relockVerifiedPin');
        // Aug-2026 — clear a rider's unlock request WITHOUT unlocking (banner ✕).
        Route::post('/{id}/dismiss-pin-unlock-request', [\App\Http\Controllers\CRM\CustomerController::class, 'dismissPinUnlockRequest'])->name('customers.dismissPinUnlockRequest');
        Route::post('/{id}/geocode', [\App\Http\Controllers\CRM\CustomerController::class, 'geocode'])->name('customers.geocode');
        Route::post('/{id}/geocode-single', [\App\Http\Controllers\CRM\CustomerController::class, 'geocodeSingle'])->name('customers.geocode-single');
        Route::delete('/{id}', [\App\Http\Controllers\CRM\CustomerController::class, 'destroy'])->name('customers.destroy');
    });
    
    // Product Management Routes
    Route::get('/products', [\App\Http\Controllers\CRM\ProductController::class, 'index'])->name('products.index');
    Route::get('/products/search', [\App\Http\Controllers\CRM\ProductController::class, 'search'])->name('products.search.alt');
    Route::post('/products/bulk-adjust-prices', [\App\Http\Controllers\CRM\ProductController::class, 'bulkAdjustPrices'])->name('products.bulk_adjust_prices');
    Route::post('/products/bulk-adjust-prices/preview', [\App\Http\Controllers\CRM\ProductController::class, 'previewBulkAdjustPrices'])->name('products.bulk_adjust_prices.preview');
    Route::post('/products/quick-update-price', [\App\Http\Controllers\CRM\ProductController::class, 'quickUpdatePrice'])->name('products.quick_update_price');
    Route::post('/products/bulk-set-weight-factor', [\App\Http\Controllers\CRM\ProductController::class, 'bulkSetWeightFactor'])->name('products.bulk_set_weight_factor');
    // Bulk category assignment
    Route::post('/products/bulk-category-summary', [\App\Http\Controllers\CRM\ProductController::class, 'bulkCategorySummary'])->name('products.bulk_category_summary');
    Route::post('/products/bulk-category-preview', [\App\Http\Controllers\CRM\ProductController::class, 'bulkCategoryPreview'])->name('products.bulk_category_preview');
    Route::post('/products/bulk-category-apply', [\App\Http\Controllers\CRM\ProductController::class, 'bulkCategoryApply'])->name('products.bulk_category_apply');
    Route::post('/api/products/weight-factors', [\App\Http\Controllers\CRM\ProductController::class, 'getWeightFactors'])->name('api.products.weight_factors');
    // Attribute management
    Route::get('/products/attributes', [\App\Http\Controllers\CRM\ProductController::class, 'attributes'])->name('products.attributes');
    Route::post('/products/attributes/labels', [\App\Http\Controllers\CRM\ProductController::class, 'saveAttributeLabels'])->name('products.attributes.labels');
    Route::post('/products/attributes/groups', [\App\Http\Controllers\CRM\ProductController::class, 'createAttributeGroup'])->name('products.attributes.groups.create');
    Route::post('/products/attributes/groups/add-products', [\App\Http\Controllers\CRM\ProductController::class, 'addProductsToGroup'])->name('products.attributes.groups.add_products');
    Route::post('/products/attributes/apply', [\App\Http\Controllers\CRM\ProductController::class, 'applyAttributeRules'])->name('products.attributes.apply');
    Route::post('/products/attributes/preview', [\App\Http\Controllers\CRM\ProductController::class, 'previewAttributeRules'])->name('products.attributes.preview');
    Route::get('/products/lookup', [\App\Http\Controllers\CRM\ProductController::class, 'lookup'])->name('products.lookup');
    Route::post('/products/attributes/preview-auto', [\App\Http\Controllers\CRM\ProductController::class, 'previewAutoRules'])->name('products.attributes.preview_auto');
    Route::post('/products/attributes/save-rules', [\App\Http\Controllers\CRM\ProductController::class, 'saveAutoRules'])->name('products.attributes.save_rules');
    Route::post('/products/attributes/coverage', [\App\Http\Controllers\CRM\ProductController::class, 'getCoverageSummary'])->name('products.attributes.coverage');
    Route::post('/products/attributes/apply-saved', [\App\Http\Controllers\CRM\ProductController::class, 'applySavedRules'])->name('products.attributes.apply_saved');
    Route::post('/products/attributes/rename-category', [\App\Http\Controllers\CRM\ProductController::class, 'renameCategory'])->name('products.attributes.rename_category');
    Route::post('/products/attributes/get-products-by-rule', [\App\Http\Controllers\CRM\ProductController::class, 'getProductsByRule'])->name('products.attributes.get_products_by_rule');
    Route::post('/products/check-sku', [\App\Http\Controllers\CRM\ProductController::class, 'checkSku'])->name('products.check_sku');
    // Category Level-1 sales vs purchases (Aug-2026).
    // ⚠ Must stay ABOVE the /products/{id} catch-all below, or the word
    //   "category-report" is swallowed as an order id and 404s.
    Route::get('/products/category-report', [\App\Http\Controllers\CRM\CategoryReportController::class, 'index'])->name('products.category_report');
    Route::get('/products/category-report/drill', [\App\Http\Controllers\CRM\CategoryReportController::class, 'drill'])->name('products.category_report.drill');
    Route::get('/products/category-report/freezer-drill', [\App\Http\Controllers\CRM\CategoryReportController::class, 'freezerDrill'])->name('products.category_report.freezer_drill');
    // Held stock (chiller OR freezer) behind one cell — ?section= picks which.
    Route::get('/products/category-report/stock-drill', [\App\Http\Controllers\CRM\CategoryReportController::class, 'stockDrill'])->name('products.category_report.stock_drill');
    Route::get('/products/category-report/sales-drill', [\App\Http\Controllers\CRM\CategoryReportController::class, 'salesDrill'])->name('products.category_report.sales_drill');
    Route::post('/products/category-report/tag', [\App\Http\Controllers\CRM\CategoryReportController::class, 'saveTag'])->name('products.category_report.tag');
    Route::post('/products/category-report/visibility', [\App\Http\Controllers\CRM\CategoryReportController::class, 'saveVisibility'])->name('products.category_report.visibility');
    Route::get('/products/create', [\App\Http\Controllers\CRM\ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [\App\Http\Controllers\CRM\ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{id}/edit', [\App\Http\Controllers\CRM\ProductController::class, 'edit'])->name('products.edit');
    Route::get('/products/{id}/history', [\App\Http\Controllers\CRM\ProductController::class, 'getHistory'])->name('products.history');
    Route::put('/products/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [\App\Http\Controllers\CRM\ProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/products/import', [\App\Http\Controllers\CRM\ProductController::class, 'importProducts'])->name('products.import');
    Route::post('/products/import-all', [\App\Http\Controllers\CRM\ProductController::class, 'importAllProducts'])->name('products.import-all')->middleware('block.rider');
    Route::post('/products/{id}/sync', [\App\Http\Controllers\CRM\ProductController::class, 'syncProduct'])->name('products.sync');
    
    // Shipping Configuration Routes
    Route::get('/shipping', [\App\Http\Controllers\ShippingController::class, 'index'])->name('shipping.index');
    Route::post('/shipping/update', [\App\Http\Controllers\ShippingController::class, 'update'])->name('shipping.update');
    Route::get('/api/shipping/price', [\App\Http\Controllers\ShippingController::class, 'getPrice'])->name('shipping.price');
    
    // Coupon Management Routes
    Route::prefix('coupons')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\CouponController::class, 'index'])->name('coupons.index');
        Route::get('/create', [\App\Http\Controllers\CRM\CouponController::class, 'create'])->name('coupons.create');
        Route::post('/', [\App\Http\Controllers\CRM\CouponController::class, 'store'])->name('coupons.store');
        Route::get('/search', [\App\Http\Controllers\CRM\CouponController::class, 'search'])->name('coupons.search');
        Route::get('/active/list', [\App\Http\Controllers\CRM\CouponController::class, 'getActiveCoupons'])->name('coupons.active');
        Route::post('/validate', [\App\Http\Controllers\CRM\CouponController::class, 'validateCoupon'])->name('coupons.validate');
        Route::post('/import', [\App\Http\Controllers\CRM\CouponController::class, 'importCoupons'])->name('coupons.import');
        Route::post('/import-all', [\App\Http\Controllers\CRM\CouponController::class, 'importAllCoupons'])->name('coupons.import-all');
        Route::get('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'show'])->name('coupons.show');
        Route::get('/{id}/edit', [\App\Http\Controllers\CRM\CouponController::class, 'edit'])->name('coupons.edit');
        Route::put('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'update'])->name('coupons.update');
        Route::delete('/{id}', [\App\Http\Controllers\CRM\CouponController::class, 'destroy'])->name('coupons.destroy');
    });
    
    // User Management Routes
    Route::prefix('users')->group(function () {
        Route::get('/', [\App\Http\Controllers\SysAdmin\UserController::class, 'index'])->name('users.index');
        Route::get('/check-email', [\App\Http\Controllers\SysAdmin\UserController::class, 'checkEmail'])->name('users.check-email');
        Route::post('/bulk', [\App\Http\Controllers\SysAdmin\UserController::class, 'bulkStore'])->name('users.bulk');
        Route::post('/{id}/create-cash-account', [\App\Http\Controllers\SysAdmin\UserController::class, 'createCashAccount'])->name('users.create-cash-account');
        Route::get('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'show'])->name('users.show');
        Route::post('/', [\App\Http\Controllers\SysAdmin\UserController::class, 'store'])->name('users.store');
        Route::put('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'update'])->name('users.update');
        Route::delete('/{id}', [\App\Http\Controllers\SysAdmin\UserController::class, 'destroy'])->name('users.destroy');
    });
    
    // Role Management Routes
    Route::prefix('roles')->group(function () {
        Route::get('/', [\App\Http\Controllers\SysAdmin\RoleController::class, 'index'])->name('roles.index');
        Route::get('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'show'])->name('roles.show');
        Route::post('/', [\App\Http\Controllers\SysAdmin\RoleController::class, 'store'])->name('roles.store');
        Route::put('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'update'])->name('roles.update');
        Route::delete('/{id}', [\App\Http\Controllers\SysAdmin\RoleController::class, 'destroy'])->name('roles.destroy');
        
        // Permission management
        Route::get('/{id}/permissions', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'manage'])->name('roles.permissions.manage');
        Route::put('/{id}/permissions', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'update'])->name('roles.permissions.update');
        Route::post('/{id}/permissions/defaults', [\App\Http\Controllers\SysAdmin\RolePermissionController::class, 'setDefaults'])->name('roles.permissions.defaults');
        
        // Mobile App Permission management
        Route::get('/{id}/mobile-permissions', [\App\Http\Controllers\SysAdmin\MobilePermissionController::class, 'index'])->name('roles.mobile-permissions');
        Route::put('/{id}/mobile-permissions', [\App\Http\Controllers\SysAdmin\MobilePermissionController::class, 'update'])->name('roles.mobile-permissions.update');
    });
    
    // Request Management Routes
    Route::prefix('requests')->group(function () {
        // Specific routes MUST come before wildcard routes
        Route::get('/', [\App\Http\Controllers\Request\RequestController::class, 'index'])->name('requests.index');
        Route::get('/data', [\App\Http\Controllers\Request\RequestController::class, 'data'])->name('requests.data');
        Route::get('/create', [\App\Http\Controllers\Request\RequestController::class, 'create'])->name('requests.create');
        Route::get('/by-number/{requestNumber}', [\App\Http\Controllers\Request\RequestController::class, 'findByNumber'])->name('requests.by-number');
        Route::get('/approval/statistics', [\App\Http\Controllers\Request\RequestApprovalController::class, 'statistics'])->name('requests.approval.statistics');
        
        // Settings routes (specific routes before {id})
        Route::get('/settings', [\App\Http\Controllers\Request\RequestSettingsController::class, 'index'])->name('requests.settings.index');
        Route::put('/settings/categories/{id}/config', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateCategoryConfig'])->name('requests.settings.category.config');
        Route::post('/settings/roles/assign-level', [\App\Http\Controllers\Request\RequestSettingsController::class, 'assignRoleToLevel'])->name('requests.settings.roles.assign');
        Route::delete('/settings/roles/level/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'removeRoleFromLevel'])->name('requests.settings.roles.remove');
        Route::get('/settings/users/level/{level}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'getUsersWithLevel'])->name('requests.settings.users.level');
        Route::put('/settings/categories/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateCategory'])->name('requests.settings.category.update');
        Route::post('/settings/categories', [\App\Http\Controllers\Request\RequestSettingsController::class, 'createCategory'])->name('requests.settings.category.create');
        Route::post('/settings/categories/{id}/routing', [\App\Http\Controllers\Request\RequestSettingsController::class, 'saveCategoryRouting'])->name('requests.settings.category.routing.save');
        
        // Expense sub-categories management
        Route::get('/settings/expense-subcategories', [\App\Http\Controllers\Request\RequestSettingsController::class, 'getExpenseSubCategories'])->name('requests.settings.expense-subcategories');
        Route::put('/settings/expense-subcategories/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateExpenseSubCategory'])->name('requests.settings.expense-subcategories.update');
        Route::delete('/settings/expense-subcategories/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'deleteExpenseSubCategory'])->name('requests.settings.expense-subcategories.delete');

        // Routing Rules
        Route::get('/settings/routing-rules', [\App\Http\Controllers\Request\RequestSettingsController::class, 'getRoutingRules'])->name('requests.settings.routing-rules.index');
        Route::get('/settings/routing-rules/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'getRoutingRule'])->name('requests.settings.routing-rules.show');
        Route::post('/settings/routing-rules', [\App\Http\Controllers\Request\RequestSettingsController::class, 'createRoutingRule'])->name('requests.settings.routing-rules.store');
        Route::put('/settings/routing-rules/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'updateRoutingRule'])->name('requests.settings.routing-rules.update');
        Route::delete('/settings/routing-rules/{id}', [\App\Http\Controllers\Request\RequestSettingsController::class, 'deleteRoutingRule'])->name('requests.settings.routing-rules.destroy');
        
        // POST routes
        Route::post('/', [\App\Http\Controllers\Request\RequestController::class, 'store'])->name('requests.store');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Request\RequestController::class, 'cancel'])->name('requests.cancel');
        Route::post('/{id}/approve', [\App\Http\Controllers\Request\RequestApprovalController::class, 'approve'])->name('requests.approve');
        Route::post('/{id}/reject', [\App\Http\Controllers\Request\RequestApprovalController::class, 'reject'])->name('requests.reject');
        
        // Wildcard routes MUST come last
        Route::get('/{id}', [\App\Http\Controllers\Request\RequestController::class, 'show'])->name('requests.show');
        Route::put('/{id}', [\App\Http\Controllers\Request\RequestController::class, 'update'])->name('requests.update');
    });

    // Unified Approvals Dashboard
    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index'])->name('approvals.index');
    
    // ⭐ Online Approvals - Dedicated page for online payment approvals
    Route::get('/approvals/online', [\App\Http\Controllers\ApprovalController::class, 'onlineApprovals'])->name('approvals.online');

    // Jun-2026 — Payment auto-matching setup check (password-protected webpage).
    // Visit /admin/payments/imap-check?key=<PAYMENT_SIGNALS_CHECK_SECRET>
    Route::get('/admin/payments/imap-check', [\App\Http\Controllers\FIN\PaymentDiagnosticsController::class, 'imapCheck'])
        ->name('payments.imap-check');

    // Jun-2026 — Payment-proof signals for an order (JSON, feeds badge panels).
    Route::get('/admin/payments/order/{orderId}/signals', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'forOrder'])
        ->whereNumber('orderId')
        ->name('payments.order-signals');

    // Aug-2026 — Record a payment BY HAND (Shabib/Taimur only), and move any
    // extra into the customer's account balance. Evidence-side only: these
    // write payment SIGNALS, never order payments, so an undelivered order's
    // invoicing is untouched (the prepayment case).
    Route::post('/admin/payments/order/{orderId}/manual-proof', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'recordManualProof'])
        ->whereNumber('orderId')->name('payments.manual-proof');
    Route::get('/admin/payments/order/{orderId}/overpay', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayInfo'])
        ->whereNumber('orderId')->name('payments.overpay');
    Route::post('/admin/payments/order/{orderId}/overpay-to-balance', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayToBalance'])
        ->whereNumber('orderId')->name('payments.overpay-to-balance');
    Route::post('/admin/payments/order/{orderId}/overpay-to-tip', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayToTip'])
        ->whereNumber('orderId')->name('payments.overpay-to-tip');
    // Delete a hand-typed payment that was entered wrongly (Shabib/Taimur).
    Route::post('/admin/payments/signal/{signalId}/delete-manual', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'deleteManualProof'])
        ->whereNumber('signalId')->name('payments.delete-manual');
    // Literal path — declared before nothing that could swallow it; used after a
    // BULK approval to ask about overpaid invoices in one round trip.
    Route::post('/admin/payments/overpay-batch', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'overpayBatch'])
        ->name('payments.overpay-batch');

    // Jun-2026 — Dismiss an auto-detected combined (bulk) payment for an order.
    Route::post('/admin/payments/order/{orderId}/uncombine', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'uncombine'])
        ->whereNumber('orderId')
        ->name('payments.order-uncombine');

    // Aug-2026 — "Wrong match — remove": detach a SYSTEM-GUESSED payment from an
    // order, unlearn what it taught, and send the credit back to the money inbox.
    Route::post('/admin/payments/signal/{signalId}/unmark', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'unmark'])
        ->whereNumber('signalId')
        ->name('payments.signal-unmark');

    // Aug-2026 — pre-approval payer check: which of these orders carry a payment
    // the system only GUESSED at, from a payer we don't recognise?
    Route::post('/admin/payments/approval-check', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'approvalCheck'])
        ->name('payments.approval-check');
    Route::post('/admin/payments/signal/{signalId}/confirm-payer', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'confirmPayer'])
        ->whereNumber('signalId')
        ->name('payments.signal-confirm-payer');

    // Jun-2026 — Phase 2: one-time "balancing discount" (apply / remove).
    Route::post('/admin/payments/order/{orderId}/balance-discount', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'applyBalanceDiscount'])
        ->whereNumber('orderId')->name('payments.balance-discount');
    Route::post('/admin/payments/order/{orderId}/balance-discount/remove', [\App\Http\Controllers\FIN\PaymentSignalsController::class, 'removeBalanceDiscount'])
        ->whereNumber('orderId')->name('payments.balance-discount.remove');

    // Jun-2026 — Manual catch-up: find WhatsApp images / bank emails that were
    // missed by the live flow, create their signals, and run extraction+match.
    // Idempotent + non-interfering; powers the Operations page button.
    Route::post('/admin/payments/reconcile', [\App\Http\Controllers\FIN\PaymentDiagnosticsController::class, 'reconcile'])
        ->name('payments.reconcile');

    // Jun-2026 — save the amount tolerance (PKR) + re-evaluate recent mismatches.
    Route::post('/admin/payments/tolerance', [\App\Http\Controllers\FIN\PaymentDiagnosticsController::class, 'saveTolerance'])
        ->name('payments.tolerance');
    
    // ⭐ Online Receiving Accounts CRUD (manage bank accounts for online approvals)
    Route::prefix('online-receiving-accounts')->group(function () {
        Route::get('/', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'update']);
        Route::post('/{id}/toggle-active', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'toggleActive']);
        Route::delete('/{id}', [\App\Http\Controllers\FIN\OnlineReceivingAccountController::class, 'destroy']);
    });

    // ⭐ Bank Balances — per-bank current balance over the single ONLINE ledger
    // account, with statement drill-downs (per-bank + untagged "No bank").
    Route::get('/finance/bank-balances', [\App\Http\Controllers\FIN\BankBalancesController::class, 'index'])
        ->name('fin.bank-balances');
    // Specific route MUST come before the numeric wildcard.
    Route::get('/finance/bank-balances/unassigned/transactions', [\App\Http\Controllers\FIN\BankBalancesController::class, 'unassignedTransactions'])
        ->name('fin.bank-balances.unassigned');
    Route::get('/finance/bank-balances/{id}/transactions', [\App\Http\Controllers\FIN\BankBalancesController::class, 'transactions'])
        ->whereNumber('id')->name('fin.bank-balances.transactions');
    // Fix an untagged online movement (assign its bank) — Taimur only.
    Route::post('/finance/bank-balances/assign/{ledgerId}', [\App\Http\Controllers\FIN\BankBalancesController::class, 'assignBank'])
        ->whereNumber('ledgerId')->name('fin.bank-balances.assign');
    // Manual per-bank balance adjustment (+/- true-up) — Taimur only.
    Route::post('/finance/bank-balances/{bankId}/adjustments', [\App\Http\Controllers\FIN\BankBalancesController::class, 'storeAdjustment'])
        ->whereNumber('bankId')->name('fin.bank-balances.adjust');
    Route::post('/finance/bank-balances/adjustments/{adjustmentId}/delete', [\App\Http\Controllers\FIN\BankBalancesController::class, 'deleteAdjustment'])
        ->whereNumber('adjustmentId')->name('fin.bank-balances.adjust.delete');
    // Set the "start tracking No-bank from" date — Taimur only.
    Route::post('/finance/bank-balances/unassigned/since', [\App\Http\Controllers\FIN\BankBalancesController::class, 'setUnassignedSince'])
        ->name('fin.bank-balances.unassigned.since');

    // Debug route for testing virtual assignment
    Route::get('/test-virtual-assignment', function() {
        $requests = \App\Models\Request\RequestModel::where('status', 'pending')
            ->with(['category', 'paymentSourceAccount'])
            ->limit(5)
            ->get();
        
        $results = [];
        foreach ($requests as $req) {
            $assignee = \App\Http\Controllers\ApprovalController::testGetVirtualAssignee($req, 1);
            $results[] = [
                'request_number' => $req->request_number,
                'category' => $req->category->category_code ?? 'N/A',
                'payment_source_id' => $req->payment_source_account_id,
                'payment_source_name' => $req->paymentSourceAccount->account_name ?? 'N/A',
                'assigned_to_id' => $assignee,
            ];
        }
        
        return response()->json($results);
    });
    
    // ⭐ Khaas Mode Routes (Business Unit dedicated area)
    Route::prefix('khaas')->name('khaas.')->group(function () {
        Route::get('/', [\App\Http\Controllers\KhaasController::class, 'dashboard'])->name('dashboard');
        Route::get('/products', [\App\Http\Controllers\KhaasController::class, 'products'])->name('products');
        Route::get('/operations', [\App\Http\Controllers\KhaasController::class, 'operations'])->name('operations');
        // Keep individual routes for backward compatibility
        Route::get('/vendors', [\App\Http\Controllers\KhaasController::class, 'vendors'])->name('vendors');
        Route::get('/expenses', [\App\Http\Controllers\KhaasController::class, 'expenses'])->name('expenses');
        Route::get('/transfers', [\App\Http\Controllers\KhaasController::class, 'transfers'])->name('transfers');
        Route::post('/transfers/{id}/approve', [\App\Http\Controllers\KhaasController::class, 'approveTransfer'])->name('transfers.approve');
        Route::post('/transfers/{id}/reject', [\App\Http\Controllers\KhaasController::class, 'rejectTransfer'])->name('transfers.reject');
        Route::post('/warehouse/stock', [\App\Http\Controllers\KhaasController::class, 'updateStock'])->name('warehouse.stock');
        Route::post('/warehouse/transfer', [\App\Http\Controllers\KhaasController::class, 'initiateTransfer'])->name('warehouse.transfer');
        Route::post('/store/stock', [\App\Http\Controllers\KhaasController::class, 'updateStoreStock'])->name('store.stock');
        Route::get('/sales-report', [\App\Http\Controllers\KhaasController::class, 'salesReport'])->name('sales-report');
        Route::get('/sales-report/daily-ajax', [\App\Http\Controllers\KhaasController::class, 'salesReportDailyWeb'])->name('sales-report.daily-ajax');
        Route::get('/sales-report/product/{productId}/daily', [\App\Http\Controllers\KhaasController::class, 'productDailyBreakdown'])->name('sales-report.product-daily');
        // 📊 Sep-2026 Month Review — packs made (same engine as the mobile
        // Inventory Report) alongside the month's spend split into
        // product / fixed / one-time. Cost half gated by view_khaas_month_review.
        Route::get('/month-review', [\App\Http\Controllers\KhaasController::class, 'monthReview'])->name('month-review');
        Route::post('/month-review/cost-type', [\App\Http\Controllers\KhaasController::class, 'setCostType'])->name('month-review.cost-type');
        // ⭐ Aug-2026 transfer REQUESTS — asking the warehouse for stock. Distinct from
        // /transfers/* above: a request moves nothing until it is accepted.
        Route::post('/transfer-requests', [\App\Http\Controllers\KhaasController::class, 'createTransferRequest'])->name('transfer-requests.create');
        Route::post('/transfer-requests/{id}/cancel', [\App\Http\Controllers\KhaasController::class, 'cancelTransferRequest'])->name('transfer-requests.cancel');
        Route::post('/transfer-requests/{id}/accept', [\App\Http\Controllers\KhaasController::class, 'acceptTransferRequest'])->name('transfer-requests.accept');
        Route::post('/transfer-requests/{id}/decline', [\App\Http\Controllers\KhaasController::class, 'declineTransferRequest'])->name('transfer-requests.decline');
        Route::get('/products/{productId}/pending-orders', [\App\Http\Controllers\KhaasController::class, 'pendingOrdersBreakdown'])->name('products.pending-orders');
        Route::get('/products/{productId}/store-log', [\App\Http\Controllers\KhaasController::class, 'getStoreInventoryLog'])->name('products.store-log');
        Route::get('/products/{productId}/warehouse-log', [\App\Http\Controllers\KhaasController::class, 'getWarehouseInventoryLog'])->name('products.warehouse-log');
        // Last N movements in/out of one of this BU's payment accounts (Operations → Expenses).
        Route::get('/account-activity/{accountId}', [\App\Http\Controllers\KhaasController::class, 'accountActivity'])->name('account-activity');

        // Meat Order & Inventory views
        Route::get('/meat-order', [\App\Http\Controllers\KhaasController::class, 'meatOrder'])->name('meat-order');
        Route::post('/meat-order/place', [\App\Http\Controllers\KhaasController::class, 'placeStorageOrder'])->name('meat-order.place');
        Route::post('/meat-order/{id}/receive', [\App\Http\Controllers\KhaasController::class, 'receiveStorageOrder'])->name('meat-order.receive');
        Route::get('/inventory', [\App\Http\Controllers\KhaasController::class, 'inventory'])->name('inventory');
        Route::post('/inventory/demand/create', [\App\Http\Controllers\KhaasController::class, 'createDemand'])->name('inventory.demand.create');
        Route::post('/inventory/demand/{id}/accept', [\App\Http\Controllers\KhaasController::class, 'acceptDemand'])->name('inventory.demand.accept');
        Route::post('/inventory/demand/{id}/cancel', [\App\Http\Controllers\KhaasController::class, 'cancelDemand'])->name('inventory.demand.cancel');
        Route::post('/inventory/recipe/save', [\App\Http\Controllers\KhaasController::class, 'saveRecipe'])->name('inventory.recipe.save');
        Route::post('/inventory/recipe/{id}/delete', [\App\Http\Controllers\KhaasController::class, 'deleteRecipe'])->name('inventory.recipe.delete');
        Route::post('/inventory/custom-material', [\App\Http\Controllers\KhaasController::class, 'saveCustomMaterialWeb'])->name('inventory.custom-material.save');
        Route::post('/inventory/storage-config', [\App\Http\Controllers\KhaasController::class, 'updateStorageConfig'])->name('inventory.storage-config');

        // ❄🧾 Sep-2026 RECIPES + INGREDIENT COSTING — the same controller the phone
        // calls, so the Planning tab and the app cannot show different recipes.
        Route::get('/ingredients', [\App\Http\Controllers\Khaas\RecipeController::class, 'ingredients'])->name('ingredients');
        Route::post('/ingredients', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveIngredient'])->name('ingredients.save');
        Route::post('/ingredients/{id}/deactivate', [\App\Http\Controllers\Khaas\RecipeController::class, 'deactivateIngredient'])->name('ingredients.deactivate');
        // ⭐ Sep-26: one unit per ingredient, changed everywhere at once (recipes + tagged products).
        Route::get('/ingredients/{id}/unit-impact', [\App\Http\Controllers\Khaas\RecipeController::class, 'unitImpact'])->name('ingredients.unit-impact');
        Route::post('/ingredients/{id}/change-unit', [\App\Http\Controllers\Khaas\RecipeController::class, 'changeUnit'])->name('ingredients.change-unit');
        Route::get('/ingredients/month', [\App\Http\Controllers\Khaas\RecipeController::class, 'ingredientMonth'])->name('ingredients.month');
        Route::post('/ingredients/opening', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveOpening'])->name('ingredients.opening');
        // ❄🧂 Sep-30: ingredient stock (start + bought − used = left), hide/unhide, replace.
        Route::get('/ingredients/stock', [\App\Http\Controllers\Khaas\RecipeController::class, 'stockSheet'])->name('ingredients.stock');
        Route::post('/ingredients/stock', [\App\Http\Controllers\Khaas\RecipeController::class, 'saveStock'])->name('ingredients.stock.save');
        Route::get('/ingredients/{id}/stock-history', [\App\Http\Controllers\Khaas\RecipeController::class, 'stockHistory'])->name('ingredients.stock-history');
        Route::post('/ingredients/{id}/activate', [\App\Http\Controllers\Khaas\RecipeController::class, 'activateIngredient'])->name('ingredients.activate');
        Route::get('/ingredients/{id}/replace-impact', [\App\Http\Controllers\Khaas\RecipeController::class, 'replaceImpact'])->name('ingredients.replace-impact');
        Route::post('/ingredients/{id}/replace', [\App\Http\Controllers\Khaas\RecipeController::class, 'replaceIngredient'])->name('ingredients.replace');
        Route::get('/recipe', [\App\Http\Controllers\Khaas\RecipeController::class, 'show'])->name('recipe.show');
        Route::post('/recipe', [\App\Http\Controllers\Khaas\RecipeController::class, 'save'])->name('recipe.save');
        Route::get('/recipe/coverage', [\App\Http\Controllers\Khaas\RecipeController::class, 'coverage'])->name('recipe.coverage');
        Route::get('/consumption', [\App\Http\Controllers\Khaas\RecipeController::class, 'consumption'])->name('consumption');
        Route::get('/product-costs', [\App\Http\Controllers\Khaas\RecipeController::class, 'productCosts'])->name('product-costs');
        Route::post('/consumption/reapply', [\App\Http\Controllers\Khaas\RecipeController::class, 'reapply'])->name('consumption.reapply');
    });

    // 📦 Storage (Supplies) — packaging bought in bulk, charged to expenses one packet
    // at a time as it is used. The SAME controller methods are mounted on
    // /api/supplies/* for the mobile app, so the two surfaces cannot drift apart.
    Route::prefix('supplies')->name('supplies.')->group(function () {
        Route::get('/', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'index'])->name('index');
        Route::get('/stock', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getStock'])->name('stock');
        Route::get('/products', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getProducts'])->name('products');
        Route::get('/history', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getHistory'])->name('history');
        Route::get('/my-takeouts', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getMyTakeouts'])->name('my-takeouts');
        Route::get('/alerts', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'alerts'])->name('alerts');
        Route::get('/{productId}/batches', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getBatches'])->name('batches');
        Route::get('/{productId}/packets', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'getPackets'])->name('packets');
        Route::post('/products', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'saveProduct'])->name('products.store');
        Route::post('/products/{id}', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'saveProduct'])->name('products.update');
        Route::post('/batches', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'bookBatch'])->name('batches.store');
        Route::post('/batches/{batchId}/void', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'voidBatch'])->name('batches.void');
        Route::post('/batches/{batchId}/preview-correction', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'previewCorrection'])->name('batches.preview-correction');
        Route::post('/batches/{batchId}/correct-price', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'correctPrice'])->name('batches.correct-price');
        Route::post('/{productId}/count', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'recordCount'])->name('count');
        Route::post('/decode', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'decodeBarcode'])->name('decode');
        Route::post('/resolve-scan', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'resolveScan'])->name('resolve-scan');
        Route::post('/take-out', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'takeOut'])->name('take-out');
        Route::post('/take-out/{takeoutId}/undo', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'undoTakeout'])->name('take-out.undo');
        // ⭐ Round 3. These three sit BELOW the literal `/take-out` post above and carry a
        // second path segment, so no wildcard in this group can swallow them.
        Route::post('/take-out/quote', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'quoteTakeout'])->name('take-out.quote');
        Route::post('/take-out/{takeoutId}/delete', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'deleteTakeout'])->name('take-out.delete');
        Route::post('/take-out/{takeoutId}/preview-edit', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'previewTakeoutEdit'])->name('take-out.preview-edit');
        Route::post('/take-out/{takeoutId}/edit', [\App\Http\Controllers\FIN\SupplyStorageController::class, 'editTakeout'])->name('take-out.edit');
    });

    // The approval switch lives OFF the /supplies prefix on purpose.
    // ⚠ PATH SHAPE IS LOAD-BEARING: prod's StackProtect bot filter challenges by URL
    // SHAPE and has silently killed paths ending in /settings or /config. This mirrors
    // /admin/operations/scan-verify, which works in production today.
    Route::post('/admin/operations/supply-approval',
        [\App\Http\Controllers\FIN\SupplyStorageController::class, 'setApprovalSwitch'])
        ->name('admin.operations.supply-approval')->middleware('block.rider');

    // ⭐ Overnight Storage (NF store chiller/freezer tracker — standalone, no inventory impact)
    // Same controller methods are mounted on /api/overnight/* for the mobile app.
    Route::prefix('overnight')->name('overnight.')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'index'])->name('index');
        Route::get('/items', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getItems'])->name('items');
        Route::get('/products', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getProductsMap'])->name('products');
        Route::post('/items', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'addItems'])->name('items.add');
        Route::post('/move', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'moveItems'])->name('move');
        Route::post('/take-out', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'takeOutItems'])->name('take-out');
        Route::post('/verify', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'verifyItems'])->name('verify');
        Route::get('/history', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getHistory'])->name('history');
        Route::get('/daily-summary', [\App\Http\Controllers\CRM\OvernightStorageController::class, 'getDailySummary'])->name('daily-summary');
    });

    // 🐔 La Carne (chicken supplier board — open / out-for-delivery / delivered
    // chicken quantities for a date, plus the dated supplier invoice photos).
    // Same controller methods are mounted on /api/rider/store/lacarne/* for the
    // mobile app, so web and phone can never disagree about the numbers or the
    // permission rules.
    Route::prefix('lacarne')->name('lacarne.')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\LaCarneController::class, 'index'])->name('index');
        Route::get('/board', [\App\Http\Controllers\CRM\LaCarneController::class, 'board'])->name('board');
        Route::post('/photos', [\App\Http\Controllers\CRM\LaCarneController::class, 'addPhotos'])->name('photos.add');
        Route::post('/photos/{photoId}/delete', [\App\Http\Controllers\CRM\LaCarneController::class, 'deletePhoto'])->name('photos.delete');
    });

    // Finance & Ledger Routes
    Route::prefix('finance')->name('fin.')->group(function () {
        
        // Action Items Routes
        Route::prefix('action-items')->name('action-items.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ActionItemController::class, 'index'])->name('index');
            Route::get('/{id}', [\App\Http\Controllers\FIN\ActionItemController::class, 'show'])->name('show');
            Route::post('/{id}/resolve', [\App\Http\Controllers\FIN\ActionItemController::class, 'resolve'])->name('resolve');
            Route::post('/{id}/dismiss', [\App\Http\Controllers\FIN\ActionItemController::class, 'dismiss'])->name('dismiss');
            Route::post('/{id}/retry', [\App\Http\Controllers\FIN\ActionItemController::class, 'retryPosting'])->name('retry');
            Route::post('/toggle-posting', [\App\Http\Controllers\FIN\ActionItemController::class, 'toggleLedgerPosting'])->name('toggle-posting');
        });

        // ⭐ Business Unit Management Routes
        Route::prefix('business-units')->name('business-units.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'store'])->name('store');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'update'])->name('update');
            Route::delete('/{id}', [\App\Http\Controllers\FIN\BusinessUnitController::class, 'destroy'])->name('destroy');
        });
        
        // Account Management Routes
        Route::prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\AccountController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\AccountController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\AccountController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\FIN\AccountController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\AccountController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\AccountController::class, 'update'])->name('update');
            Route::post('/{id}/toggle-status', [\App\Http\Controllers\FIN\AccountController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{id}/adjust-balance', [\App\Http\Controllers\FIN\AccountController::class, 'adjustBalance'])->name('adjust-balance');
        });
        
        // Import Routes — legacy finance data import + DANGER clear-legacy (balance reset); staff only.
        Route::prefix('import')->name('import.')->middleware('block.rider')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ImportController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\ImportController::class, 'create'])->name('create');
            Route::post('/legacy', [\App\Http\Controllers\FIN\ImportController::class, 'importLegacy'])->name('legacy');
            Route::post('/clear-legacy', [\App\Http\Controllers\FIN\ImportController::class, 'clearLegacyData'])->name('clear-legacy');
            Route::delete('/{id}', [\App\Http\Controllers\FIN\ImportController::class, 'deleteImport'])->name('delete');
            Route::get('/template', [\App\Http\Controllers\FIN\ImportController::class, 'downloadTemplate'])->name('template');
            Route::get('/{id}', [\App\Http\Controllers\FIN\ImportController::class, 'show'])->name('show');
        });

        // Expense Category Routes
        Route::prefix('expense-category')->name('expense-category.')->group(function () {
            Route::post('/', [\App\Http\Controllers\FIN\ExpenseCategoryController::class, 'store'])->name('store');
        });

        // Asset Category Management
        Route::prefix('asset-category')->name('asset-category.')->group(function () {
            Route::post('/', [\App\Http\Controllers\FIN\AssetCategoryController::class, 'store'])->name('store');
        });

        // Vendor Routes
        Route::prefix('vendors')->name('vendors.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\VendorController::class, 'index'])->name('index');
            Route::get('/report', [\App\Http\Controllers\FIN\VendorController::class, 'getReport'])->name('report');
            Route::get('/create', [\App\Http\Controllers\FIN\VendorController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\VendorController::class, 'store'])->name('store');

            // 🧾 ⚠⚠ MUST STAY ABOVE `GET /{id}` — Laravel matches in registration
            //    order, so below it these resolve as a vendor named "receipt-drafts".
            Route::get('/receipt-drafts', [\App\Http\Controllers\Khaas\ReceiptController::class, 'drafts'])->name('receipt.drafts');
            Route::get('/receipt-drafts/{id}', [\App\Http\Controllers\Khaas\ReceiptController::class, 'show'])->name('receipt.show');

            Route::get('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\VendorController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'update'])->name('update');
            Route::delete('/{id}', [\App\Http\Controllers\FIN\VendorController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/toggle-status', [\App\Http\Controllers\FIN\VendorController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('/{id}/purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordPurchase'])->name('purchase');
            Route::post('/{id}/payment', [\App\Http\Controllers\FIN\VendorController::class, 'recordPayment'])->name('payment');
            Route::post('/{id}/weighted-purchase', [\App\Http\Controllers\FIN\VendorController::class, 'recordWeightedPurchase'])->name('weighted-purchase');

            // 🧾 Sep-2026 receipt capture — the same controller the phone calls.
            // ⚠ None of these write money; the card is saved through the route above.
            // ⚠⚠ The GET halves are registered ABOVE `GET /{id}` (further up this
            //    group) — here they would resolve as a vendor id of "receipt-drafts".
            Route::post('/{id}/receipt/extract', [\App\Http\Controllers\Khaas\ReceiptController::class, 'extract'])->name('receipt.extract');
            // ⭐⭐ The card's save door — claims the draft under a row lock, then replays
            //    into recordWeightedPurchase, so a retry cannot book the bill twice.
            Route::post('/{id}/receipt/record', [\App\Http\Controllers\Khaas\ReceiptController::class, 'record'])->name('receipt.record');
            Route::post('/receipt-drafts/{id}/discard', [\App\Http\Controllers\Khaas\ReceiptController::class, 'discard'])->name('receipt.discard');
            Route::post('/transaction/{id}/delete', [\App\Http\Controllers\FIN\VendorController::class, 'deleteTransaction'])->name('transaction.delete');
            Route::post('/transaction/{id}/update', [\App\Http\Controllers\FIN\VendorController::class, 'updateTransaction'])->name('transaction.update');
            Route::post('/toggle-expand', [\App\Http\Controllers\FIN\VendorController::class, 'toggleExpandAll'])->name('toggle-expand');
            
            // Vendor Products Management
            Route::get('/{id}/products', [\App\Http\Controllers\FIN\VendorProductController::class, 'index'])->name('products');
            Route::get('/{id}/products/list', [\App\Http\Controllers\FIN\VendorProductController::class, 'list'])->name('products.list');
            Route::post('/{id}/products', [\App\Http\Controllers\FIN\VendorProductController::class, 'store'])->name('products.store');
            Route::put('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'update'])->name('products.update');
            Route::post('/{vendorId}/products/{productId}/toggle', [\App\Http\Controllers\FIN\VendorProductController::class, 'toggleStatus'])->name('products.toggle');
            Route::post('/{vendorId}/products/{productId}/set-default', [\App\Http\Controllers\FIN\VendorProductController::class, 'setAsDefault'])->name('products.set-default');
            Route::delete('/{vendorId}/products/{productId}', [\App\Http\Controllers\FIN\VendorProductController::class, 'destroy'])->name('products.delete');
            // 🧾↩ Oct-7: count a product's earlier bills after it is tagged (opt-in) / drop a tag.
            Route::post('/{vendorId}/products/{productId}/count-past-bills', [\App\Http\Controllers\FIN\VendorProductController::class, 'countPastBills'])->name('products.count-past-bills');
            Route::post('/{vendorId}/products/{productId}/unlink-ingredient', [\App\Http\Controllers\FIN\VendorProductController::class, 'unlinkIngredient'])->name('products.unlink-ingredient');
        });

        // Asset Management Routes
        Route::prefix('assets')->name('assets.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\AssetController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\FIN\AssetController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\FIN\AssetController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\FIN\AssetController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [\App\Http\Controllers\FIN\AssetController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\FIN\AssetController::class, 'update'])->name('update');
        });

        // Employee Cash Routes
        Route::prefix('employee')->name('employee.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'index'])->name('index');
            Route::get('/dashboard', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'dashboard'])->name('dashboard');
            Route::get('/outstanding-invoices', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'allOutstandingInvoices'])->name('all-outstanding-invoices');
            Route::get('/invoice-breakdown', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'getInvoiceBreakdown'])->name('invoice-breakdown');
            Route::get('/debug-missing-metadata', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'debugMissingMetadata'])->name('debug-missing-metadata');
            Route::get('/debug-invoice/{invoiceId}', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'debugInvoiceSettlement'])->name('debug-invoice-settlement');

            // ⚠⚠ EVERY single-segment LITERAL route in this group MUST be declared
            // ABOVE `/{id}` below. Laravel matches in registration order, so a
            // literal registered after it is swallowed by the wildcard, which then
            // tries to resolve the literal as an AccountModel id and 404s with
            // "No query results for model [AccountModel] <your-route>".
            //
            // That is exactly what happened to followup-heartbeat, added in
            // Aug-2026 at the BOTTOM of this group: the Daily Closing staleness
            // check it powers had never once succeeded, so the page silently
            // never learned that anything had changed. Found Sep-2026.
            // (`followup-precheck/{orderId}` escaped only because it has two
            // segments, which `/{id}` cannot match.)
            //
            //   heartbeat      — a handful of COUNTs, safe to poll, that says
            //                    whether anything changed since the page loaded.
            //   panels-refresh — re-renders ONLY the Requests and Messages panes
            //                    so the page can freshen them in place, without a
            //                    ~1,130ms full reload that would destroy scroll,
            //                    open groups and any half-finished approval.
            Route::get('/followup-heartbeat', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'followUpHeartbeat'])->name('followup-heartbeat');
            Route::get('/panels-refresh', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'dailyClosingPanels'])->name('panels-refresh');

            Route::get('/{id}', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'show'])->name('show');
            Route::post('/{id}/deposit', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordDeposit'])->name('deposit');
            Route::post('/{id}/settlement-deposit', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordSettlementDeposit'])->name('settlement-deposit');
            Route::post('/{id}/short-cash-settlement', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordShortCashSettlement'])->name('short-cash-settlement');
            Route::get('/{id}/outstanding-invoices', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'getOutstandingInvoices'])->name('outstanding-invoices');
            Route::post('/{id}/adjustment', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordAdjustment'])->name('adjustment');
            Route::post('/{id}/expense-request', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'createExpenseRequest'])->name('expense-request');
            
            // Company Account Transaction Routes
            Route::post('/{id}/company-receive', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyReceipt'])->name('company-receive');
            Route::post('/{id}/company-payment', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyPayment'])->name('company-payment');
            Route::post('/{id}/company-transfer', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'recordCompanyTransfer'])->name('company-transfer');
            
            Route::post('/mark-online-message-sent/{orderId}', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'markOnlineMessageSentWeb'])->name('mark-online-message-sent');

            // Aug-2026 — Daily Closing is a load-time snapshot, so a proof that
            // lands after the page opens is invisible. `precheck` re-reads ONE
            // order's proof/settled state at the moment of sending, so a stale
            // page can't nag a customer who has already paid.
            // ⭐ Two segments, so `/{id}` above cannot swallow it — unlike its
            // heartbeat sibling, which had to be moved up there. See the note by
            // `/{id}` before adding any single-segment route to this group.
            Route::get('/followup-precheck/{orderId}', [\App\Http\Controllers\FIN\EmployeeCashController::class, 'followUpPrecheck'])->name('followup-precheck');
        });

        // Ledger Hub (parallel-run modern UI — additive; reads existing data, writes via existing
        // endpoints; every old finance page stays live and untouched until phase-out).
        // Review the assistant's learned "this bank account belongs to X" rules
        // from the vendor / customer side (they were only ever writable before).
        Route::get('/counterparty-accounts', [\App\Http\Controllers\FIN\CounterpartyAccountController::class, 'index'])->name('counterparty-accounts.index');
        Route::post('/counterparty-accounts', [\App\Http\Controllers\FIN\CounterpartyAccountController::class, 'store'])->name('counterparty-accounts.store');
        Route::post('/counterparty-accounts/{id}/deactivate', [\App\Http\Controllers\FIN\CounterpartyAccountController::class, 'deactivate'])->name('counterparty-accounts.deactivate');

        Route::prefix('hub')->name('hub.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\Hub\HubController::class, 'overview'])->name('overview');
            Route::get('/accounts', [\App\Http\Controllers\FIN\Hub\HubController::class, 'accounts'])->name('accounts');
            Route::get('/account/{id}', [\App\Http\Controllers\FIN\Hub\HubController::class, 'accountDetail'])->name('account');
            // 👤 Who may pay FROM this account (t_fin_account_users). Read is open to
            // anyone who can open the account; writes are gated on
            // `manage_account_users` inside the controller so they return JSON the
            // panel can show rather than an HTML redirect.
            Route::get('/account/{id}/users', [\App\Http\Controllers\FIN\Hub\HubController::class, 'accountUsers'])->name('account-users');
            Route::post('/account/{id}/users', [\App\Http\Controllers\FIN\Hub\HubController::class, 'saveAccountUser'])->name('account-users.save');
            Route::delete('/account/{id}/users/{userId}', [\App\Http\Controllers\FIN\Hub\HubController::class, 'deleteAccountUser'])->name('account-users.delete');
            // 🏦 The keeper's own count of the till he holds (web door; the mobile door is
            // POST /rider/till/count). Gated inside the controller on is_keeper so it can
            // return JSON the modal shows rather than an HTML redirect.
            Route::post('/account/{id}/count', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tillCount'])
                ->whereNumber('id')->name('account-count');
            // 💵 The floating cash pill. No permission gate — the service scopes to the
            // accounts the caller is tagged on, so it answers 0 for everyone else.
            Route::get('/watch/count', [\App\Http\Controllers\FIN\Hub\HubController::class, 'watchCount'])->name('watch.count');
            Route::get('/watch/list', [\App\Http\Controllers\FIN\Hub\HubController::class, 'watchList'])->name('watch.list');
            Route::post('/watch/seen', [\App\Http\Controllers\FIN\Hub\HubController::class, 'watchSeen'])->name('watch.seen');
            Route::get('/data/transfer-accounts', [\App\Http\Controllers\FIN\Hub\HubController::class, 'transferAccountsData'])->name('transfer-accounts');
            Route::get('/vendors', [\App\Http\Controllers\FIN\Hub\HubController::class, 'vendors'])->name('vendors');
            Route::get('/vendor/{id}', [\App\Http\Controllers\FIN\Hub\HubController::class, 'vendorDetail'])->name('vendor');
            // One collapsed month's rows, fetched when it is opened (keeps big statements light).
            Route::get('/vendor/{id}/month/{ym}', [\App\Http\Controllers\FIN\Hub\HubController::class, 'vendorMonth'])->name('vendor-month');
            Route::get('/banks', [\App\Http\Controllers\FIN\Hub\HubController::class, 'banks'])->name('banks');
            // ⇄ Move money between our own banks: pool unchanged, only the split moves.
            Route::post('/bank-transfer', [\App\Http\Controllers\FIN\Hub\HubController::class, 'bankTransfer'])->name('bank-transfer');
            Route::get('/bank/{id}', [\App\Http\Controllers\FIN\Hub\HubController::class, 'bankDetail'])->name('bank');
            Route::get('/health', [\App\Http\Controllers\FIN\Hub\HubController::class, 'health'])->name('health');
            // 💵 Tips held for staff. Reading is open to anyone who can open the Hub;
            // the two money actions are gated inside the controller (Shabib/Taimur for
            // a payout, Taimur for the opening balance and for undoing one) so they
            // return JSON the page can show instead of an HTML redirect.
            Route::get('/tips', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tips'])->name('tips');
            Route::post('/tips/payout', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tipsPayout'])->name('tips.payout');
            Route::post('/tips/opening', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tipsOpening'])->name('tips.opening');
            Route::post('/tips/payout/{id}/undo', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tipsUndoPayout'])
                ->whereNumber('id')->name('tips.payout.undo');
            // Prod has no shell for `php artisan tips:backfill` — same idempotent sync, Taimur only.
            Route::post('/tips/backfill', [\App\Http\Controllers\FIN\Hub\HubController::class, 'tipsBackfill'])->name('tips.backfill');
            // Taimur-only money corrections (guarded inside the controller, not by middleware, so
            // they return JSON the Hub modals can show instead of an HTML redirect).
            Route::post('/external', [\App\Http\Controllers\FIN\Hub\HubController::class, 'externalMove'])->name('external');
            Route::post('/rebalance', [\App\Http\Controllers\FIN\Hub\HubController::class, 'rebalance'])->name('rebalance');
        });

        // Ledger Routes (Overall Ledger & Transfers)
        Route::prefix('ledger')->name('ledger.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\LedgerController::class, 'index'])->name('index');
            Route::get('/transfer', [\App\Http\Controllers\FIN\LedgerController::class, 'createTransfer'])->name('transfer');
            Route::post('/transfer', [\App\Http\Controllers\FIN\LedgerController::class, 'storeTransfer'])->name('transfer.store');
            Route::get('/approval-details/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'getApprovalDetails'])->name('approval-details');
            Route::get('/transaction/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'getTransactionDetails'])->name('transaction');
            Route::post('/transaction/{id}/update', [\App\Http\Controllers\FIN\LedgerController::class, 'updateTransaction'])->name('transaction.update');
            Route::get('/{id}', [\App\Http\Controllers\FIN\LedgerController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [\App\Http\Controllers\FIN\LedgerController::class, 'approve'])->name('approve');
            Route::post('/{id}/approve-l1-only', [\App\Http\Controllers\FIN\LedgerController::class, 'approveAtL1Only'])->name('approve-l1-only');
            Route::post('/{id}/reject', [\App\Http\Controllers\FIN\LedgerController::class, 'reject'])->name('reject');
            // Revert an accidentally-approved online invoice back to pending (Taimur-only; enforced in controller)
            Route::post('/{id}/revert-to-pending', [\App\Http\Controllers\FIN\LedgerController::class, 'revertToPending'])->name('revert-to-pending');
            
            // Ledger Adjustments (for order modifications after delivery)
            Route::prefix('adjustments')->name('adjustments.')->group(function () {
                Route::get('/', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'index'])->name('index');
                Route::get('/{id}', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'show'])->name('show');
                Route::post('/{id}/approve', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'approve'])->name('approve');
                Route::post('/{id}/reject', [\App\Http\Controllers\FIN\LedgerAdjustmentController::class, 'reject'])->name('reject');
            });
            
           // Ledger Audit Routes (for detecting and fixing ledger integrity issues)
           Route::prefix('audit')->name('audit.')->group(function () {
               Route::get('/report', [\App\Http\Controllers\FIN\LedgerAuditController::class, 'getAuditReport'])->name('report');
               Route::post('/fix-missing-invoices', [\App\Http\Controllers\FIN\LedgerAuditController::class, 'fixMissingInvoices'])->name('fix-missing-invoices');
               Route::post('/fix-missing-expenses', [\App\Http\Controllers\FIN\LedgerAuditController::class, 'fixMissingExpenses'])->name('fix-missing-expenses');
               Route::post('/fix-incomplete-settlements', [\App\Http\Controllers\FIN\LedgerAuditController::class, 'fixIncompleteSettlements'])->name('fix-incomplete-settlements');
           });
        });
        
        // Expense Management & Settlement Routes
        Route::prefix('expenses')->name('expenses.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'index'])->name('index');
            // May-2026 (Phase 3) — dedicated Qurbani Expenses tab.
            // Same controller method, qurbani=1 flag flips it into
            // year-scoped Qurbani-only mode with revenue card etc.
            Route::get('/qurbani', function (\Illuminate\Http\Request $request) {
                $request->merge(['qurbani' => '1']);
                return app(\App\Http\Controllers\FIN\ExpenseManagementController::class)->index($request);
            })->name('qurbani');
            Route::post('/{id}/settle', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'settle'])->name('settle');
            Route::post('/bulk-settle', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'bulkSettle'])->name('bulk-settle');
            Route::get('/{id}/settlement-details', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'getSettlementDetails'])->name('settlement-details');
            Route::delete('/{id}', [\App\Http\Controllers\FIN\ExpenseManagementController::class, 'destroy'])->name('destroy');
        });
    });
    
    // Invoices — Analysis (Jul-2026). Read-only invoice explorer for owner/analyst
    // logins; deliberately separate from the operational /orders page. All GET.
    // Literal /data + /export are declared before the numeric {id} so the wildcard
    // never swallows them.
    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [\App\Http\Controllers\CRM\InvoiceAnalysisController::class, 'index'])->name('index');
        Route::get('/data', [\App\Http\Controllers\CRM\InvoiceAnalysisController::class, 'data'])->name('data');
        Route::get('/export', [\App\Http\Controllers\CRM\InvoiceAnalysisController::class, 'export'])->name('export');
        Route::get('/{id}', [\App\Http\Controllers\CRM\InvoiceAnalysisController::class, 'detail'])->whereNumber('id')->name('detail');
    });

    // ⭐ Reports (Web version of mobile store mode Reports tab)
    Route::get('/reports', function () {
        return view('reports.index');
    })->name('reports.index');
    Route::prefix('reports/api')->group(function () {
        Route::get('/monthly-summary', [\App\Http\Controllers\API\ReportsController::class, 'getMonthlySummary']);
        Route::get('/month-details', [\App\Http\Controllers\API\ReportsController::class, 'getMonthDetails']);
        Route::get('/daily-summary', [\App\Http\Controllers\API\ReportsController::class, 'getDailySummary']);
        Route::get('/daily-details', [\App\Http\Controllers\API\ReportsController::class, 'getDailyDetails']);
    });
    
    // HR & Salary Management Routes
    Route::prefix('hr')->name('hr.')->group(function () {
        
        // Employee Profiles & Salary Configuration
        Route::prefix('employees')->name('employees.')->group(function () {
            Route::get('/', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'index'])->name('index');
            Route::get('/data', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getData'])->name('data');
            Route::get('/without-profiles', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getWithoutProfiles'])->name('without-profiles');
            Route::post('/bulk-create', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'bulkCreate'])->name('bulk-create');
            Route::get('/{userId}', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'show'])->name('show');
            Route::get('/{userId}/get-or-create', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getOrCreate'])->name('get-or-create');
            Route::get('/{userId}/salary-slips', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'getSalarySlips'])->name('salary-slips');
            Route::post('/{userId}/salary', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'updateSalary'])->name('salary.update');
            Route::post('/{userId}/deactivate', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'deactivate'])->name('deactivate');
            Route::post('/{userId}/activate', [\App\Http\Controllers\HR\EmployeeProfileController::class, 'activate'])->name('activate');
        });
        
        // Payroll (Phase G — the one-screen monthly payroll)
        Route::prefix('payroll')->name('payroll.')->group(function () {
            Route::get('/', [\App\Http\Controllers\HR\PayrollController::class, 'index'])->name('index');
            Route::get('/data', [\App\Http\Controllers\HR\PayrollController::class, 'data'])->name('data');
            Route::post('/set-salary', [\App\Http\Controllers\HR\PayrollController::class, 'setSalary'])->name('set-salary');
            Route::post('/give-advance', [\App\Http\Controllers\HR\PayrollController::class, 'giveAdvance'])->name('give-advance');
            // Owner-only (`void_salary_advance`): undo a wrongly-given advance.
            Route::post('/void-advance', [\App\Http\Controllers\HR\PayrollController::class, 'voidAdvance'])->name('void-advance');
            // Employee-raised advance requests: review queue + decide (approve pays immediately).
            Route::get('/pending-requests', [\App\Http\Controllers\HR\PayrollController::class, 'pendingRequests'])->name('pending-requests');
            Route::post('/approve-request', [\App\Http\Controllers\HR\PayrollController::class, 'approveRequest'])->name('approve-request');
            Route::post('/reject-request', [\App\Http\Controllers\HR\PayrollController::class, 'rejectRequest'])->name('reject-request');
            Route::post('/pay', [\App\Http\Controllers\HR\PayrollController::class, 'pay'])->name('pay');
            // Leave actions (overtime bonus / late penalty) — also consumed by the Attendance
            // month tab, which shows and settles the same pending items.
            Route::get('/leave-actions', [\App\Http\Controllers\HR\PayrollController::class, 'leaveActions'])->name('leave-actions');
            Route::post('/leave-actions/decide', [\App\Http\Controllers\HR\PayrollController::class, 'decideLeaveAction'])->name('leave-actions.decide');
            Route::post('/leave-actions/apply-all', [\App\Http\Controllers\HR\PayrollController::class, 'applyAllLeaveActions'])->name('leave-actions.apply-all');
            // Custom-schedule employees (date-range / weekly salaries)
            Route::get('/custom-data', [\App\Http\Controllers\HR\PayrollController::class, 'customData'])->name('custom-data');
            Route::post('/custom-preview', [\App\Http\Controllers\HR\PayrollController::class, 'customPreview'])->name('custom-preview');
            Route::post('/custom-pay', [\App\Http\Controllers\HR\PayrollController::class, 'payCustom'])->name('custom-pay');
            Route::post('/set-schedule', [\App\Http\Controllers\HR\PayrollController::class, 'setSchedule'])->name('set-schedule');
            Route::post('/set-business-unit', [\App\Http\Controllers\HR\PayrollController::class, 'setBusinessUnit'])->name('set-business-unit');
            // Custom running balance ("khata"): payments + absence calendar for employees
            // who have no attendance in the system. `manage_payroll` gates all of these;
            // void-payment additionally needs `void_salary_payment`.
            Route::get('/balance-calendar', [\App\Http\Controllers\HR\PayrollController::class, 'balanceCalendar'])->name('balance-calendar');
            Route::post('/balance-enable', [\App\Http\Controllers\HR\PayrollController::class, 'balanceEnable'])->name('balance-enable');
            Route::post('/balance-absence', [\App\Http\Controllers\HR\PayrollController::class, 'balanceAbsence'])->name('balance-absence');
            Route::post('/balance-payment', [\App\Http\Controllers\HR\PayrollController::class, 'balancePayment'])->name('balance-payment');
            Route::post('/balance-rate', [\App\Http\Controllers\HR\PayrollController::class, 'balanceRate'])->name('balance-rate');
            Route::post('/balance-void-payment', [\App\Http\Controllers\HR\PayrollController::class, 'balanceVoidPayment'])->name('balance-void-payment');
            Route::get('/staff-expense-detail', [\App\Http\Controllers\HR\PayrollController::class, 'staffExpenseDetail'])->name('staff-expense-detail');
            // Employee tab — one person, nothing about anyone else (the privacy view).
            Route::get('/employee', [\App\Http\Controllers\HR\PayrollController::class, 'employeeDetail'])->name('employee');
            Route::post('/forfeit-carry', [\App\Http\Controllers\HR\PayrollController::class, 'forfeitCarry'])->name('forfeit-carry');
            // Settle absences parked in an earlier month: charge, excuse, or use own leave.
            Route::post('/settle-absence', [\App\Http\Controllers\HR\PayrollController::class, 'settleAbsence'])->name('settle-absence');
            Route::post('/dismiss-absence-alert', [\App\Http\Controllers\HR\PayrollController::class, 'dismissAbsenceAlert'])->name('dismiss-absence-alert');
        });

        // Day review (Sep-2026) — verify a day's overtime, or waive late minutes, while it is
        // still fresh, so month-end is a confirmation instead of a review. Same `manage_payroll`
        // audience as payroll itself. ⚠ An unreviewed day still counts in full.
        Route::prefix('day-reviews')->name('day-reviews.')->group(function () {
            Route::get('/pending-count', [\App\Http\Controllers\HR\DayReviewController::class, 'pendingCount'])->name('pending-count');
            Route::get('/pending', [\App\Http\Controllers\HR\DayReviewController::class, 'pending'])->name('pending');
            Route::get('/month', [\App\Http\Controllers\HR\DayReviewController::class, 'forMonth'])->name('month');
            Route::post('/record', [\App\Http\Controllers\HR\DayReviewController::class, 'record'])->name('record');
        });

        // Salary Slips
        Route::prefix('salary-slips')->name('salary-slips.')->group(function () {
            Route::get('/', [\App\Http\Controllers\HR\SalarySlipController::class, 'index'])->name('index');
            Route::get('/data', [\App\Http\Controllers\HR\SalarySlipController::class, 'getData'])->name('data');
            Route::get('/create', [\App\Http\Controllers\HR\SalarySlipController::class, 'create'])->name('create');
            Route::post('/calculate', [\App\Http\Controllers\HR\SalarySlipController::class, 'calculate'])->name('calculate');
            Route::post('/', [\App\Http\Controllers\HR\SalarySlipController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\HR\SalarySlipController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [\App\Http\Controllers\HR\SalarySlipController::class, 'approve'])->name('approve');
            Route::post('/{id}/cancel', [\App\Http\Controllers\HR\SalarySlipController::class, 'cancel'])->name('cancel');
            Route::delete('/{id}', [\App\Http\Controllers\HR\SalarySlipController::class, 'destroy'])->name('destroy');
            Route::get('/{id}/pdf', [\App\Http\Controllers\HR\SalarySlipController::class, 'downloadPdf'])->name('pdf');
        });
        
        // Employee Loans
        Route::prefix('loans')->name('loans.')->group(function () {
            Route::get('/', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'index'])->name('index');
            Route::get('/data', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'getData'])->name('data');
            Route::get('/create', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'show'])->name('show');
            Route::get('/{id}/payments', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'getPaymentHistory'])->name('payments');
            Route::put('/{id}', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'update'])->name('update');
            Route::post('/{id}/cancel', [\App\Http\Controllers\HR\EmployeeLoanController::class, 'cancel'])->name('cancel');
        });
    });
});

// User attendance - accessible by anyone with view_attendance permission
Route::middleware(['auth'])->group(function () {
    Route::get('/attendance/mine', [\App\Http\Controllers\CRM\AttendanceController::class, 'mine'])->name('attendance.mine');
    Route::get('/attendance/mine/data', [\App\Http\Controllers\CRM\AttendanceController::class, 'mineData'])->name('attendance.mine.data');
});

// Fix approved expense requests that are missing ledger entries (one-time admin tool)
Route::middleware(['auth'])->get('/admin/fix-expense-ledger', function () {
    $user = auth()->user();
    $isTaimur = \DB::table('t_sys_user_role as ur')
        ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
        ->where('ur.user_id', $user->id)
        ->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
        ->exists();
    if (!$isTaimur) return response()->json(['error' => 'Unauthorized'], 403);
    
    $requests = \App\Models\Request\RequestModel::where('status', 'approved')
        ->whereNull('ledger_transaction_id')
        ->where('amount', '>', 0)
        ->whereHas('category', function($q) {
            $q->where(function($q2) {
                $q2->where('form_type', 'expense')
                   ->orWhere('show_in_expenses', 1);
            })->whereNotIn('category_code', ['leave', 'salary_advance', 'invoice_approval']);
        })
        ->with(['category', 'requester'])
        ->get();
    
    if ($requests->isEmpty()) return response()->json(['message' => 'No requests to fix', 'count' => 0]);
    
    $results = [];
    $ledgerService = new \App\Services\FIN\LedgerPostingService();
    foreach ($requests as $req) {
        $result = $ledgerService->postExpenseFromRequest($req);
        $results[] = ['request' => $req->request_number, 'result' => $result];
    }
    return response()->json(['fixed' => count($results), 'details' => $results]);
});

// ── Analytics Sandbox ────────────────────────────────────────────────────────
// A walled-off prototype area for dashboard development. Only loaded when:
//   1. ANALYTICS_SANDBOX_ENABLED=true is set in .env, AND
//   2. the analytics-sandbox/ folder physically exists on disk.
// So if this routes/web.php ever ships to production by accident, with or
// without the env flag, it cannot error out — it just silently skips.
$sandboxRoutes = base_path('analytics-sandbox/routes.php');
if (filter_var(env('ANALYTICS_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
    && file_exists($sandboxRoutes)) {
    require $sandboxRoutes;
}
