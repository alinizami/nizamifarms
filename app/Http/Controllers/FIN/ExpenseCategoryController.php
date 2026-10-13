<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Services\FIN\ExpenseTypeService;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    /**
     * Store a new expense category (web forms, Operations, Request Settings, mobile).
     *
     * ⭐ Oct-2026: the rules moved into ExpenseTypeService::create() — the one engine every
     * expense-type surface uses. Same behaviour: Staff Salaries refused, Khaas (BU != 1)
     * Taimur/Admin only, an EXP_ account is created. New: a duplicate is caught by NAME in
     * any case (an inactive twin answers 409 "restore it"), and the account is the one the
     * posting code will use (pinned), so a name with "/" no longer opens two accounts.
     *
     * Response shape unchanged: {success, message, category, account_code}.
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_name' => 'required|string|max:255',
            'business_unit_id' => 'nullable|integer',
            'request_category_code' => 'nullable|string|max:50',
        ]);

        $r = ExpenseTypeService::create(
            (string) $request->input('category_name', ''),
            $request->input('business_unit_id', 1),
            $request->input('request_category_code') ?: null
        );

        $wantsJson = $request->wantsJson() || $request->ajax() || $request->expectsJson();

        if ($wantsJson) {
            $body = ['success' => $r['ok'], 'message' => $r['message']];
            if ($r['ok']) {
                $body['category'] = $r['category'];
                $body['account_code'] = $r['account_code'];
            }
            if (!empty($r['restore_id'])) {
                $body['restore_id'] = $r['restore_id'];
            }
            return response()->json($body, $r['ok'] ? 200 : ($r['status'] ?? 400));
        }

        if ($r['ok']) {
            return redirect()->route('admin.operations')
                ->with('success', $r['message'] . ' Account: ' . $r['account_code']);
        }
        if (($r['status'] ?? 0) === 403 || ($r['status'] ?? 0) === 422) {
            return redirect()->back()->with('error', $r['message']);
        }
        return redirect()->route('admin.operations')->with('error', $r['message']);
    }
}
