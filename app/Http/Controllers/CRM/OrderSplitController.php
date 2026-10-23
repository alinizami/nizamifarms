<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Services\CRM\OrderSplitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ✂ SPLIT INVOICE (Oct-2026) — the ONE door for web AND mobile.
 *
 * The same two methods are routed twice: routes/web.php (session auth, orders
 * page) and routes/api.php (Sanctum, mobile store card). Like POST /api/orders →
 * OrderController::store, there is no "API copy" to drift — and everything is
 * decided in OrderSplitService, never here.
 *
 * ⚠ Live orders only: always OrderModel (t_crm_prod_order). A Shopify STAGING id
 * must never reach this — the screens only offer Split on live orders, and the
 * live-table lookup here makes that a rule (see CLAUDE.md id-collision).
 */
class OrderSplitController extends Controller
{
    public function __construct(private OrderSplitService $splits)
    {
    }

    /** Dry run: the item list, whether it can be split, and both totals for the ticked items. */
    public function preview(Request $request, $id): JsonResponse
    {
        if (!$this->splits->userCanSplit(auth()->user())) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to split invoices.'], 403);
        }

        $order = OrderModel::find((int) $id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        return response()->json($this->splits->preview($order, $this->lineIds($request)));
    }

    /** The split itself. Re-checks everything the preview said. */
    public function store(Request $request, $id): JsonResponse
    {
        $result = $this->splits->split(
            (int) $id,
            $this->lineIds($request),
            auth()->user(),
            $this->paidAnswer($request)
        );

        if (!empty($result['success'])) {
            return response()->json($result);
        }

        $status = match ($result['code'] ?? '') {
            'forbidden'         => 403,
            'not_found'         => 404,
            'needs_paid_answer' => 409,
            'error'             => 500,
            default             => 422,
        };
        return response()->json($result, $status);
    }

    /** line_ids may arrive as an array (JSON / form) or "1,2,3" (query string). */
    private function lineIds(Request $request): array
    {
        $raw = $request->input('line_ids', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        return is_array($raw) ? $raw : [];
    }

    /** split_part_paid: true / false / absent (null = not answered). */
    private function paidAnswer(Request $request): ?bool
    {
        if (!$request->has('split_part_paid') || $request->input('split_part_paid') === null || $request->input('split_part_paid') === '') {
            return null;
        }
        return filter_var($request->input('split_part_paid'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
