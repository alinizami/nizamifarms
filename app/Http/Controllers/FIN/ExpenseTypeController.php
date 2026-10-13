<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Services\FIN\ExpenseTypeService;
use Illuminate\Http\Request;

/**
 * Expense Types manager — the JSON door shared by the web drawer (/fin/expense-category/types…)
 * and the mobile sheet (/api/rider/expenses/types…). All logic is in ExpenseTypeService.
 *
 * Reading is open to any signed-in user (it is the same list the dropdown shows).
 * Changing (rename / inactive / restore / order / group / merge / undo) needs the existing
 * mobile key `create_expense_category` — Taimur only today (owner ruling Oct-11-2026).
 */
class ExpenseTypeController extends Controller
{
    public function list(Request $request)
    {
        $bu = (int) ($request->input('business_unit_id') ?: 1);
        $rcc = $request->input('request_category_code') ?: 'expense';
        $canManage = ExpenseTypeService::canManage();

        $data = [
            'success'     => true,
            'can_manage'  => $canManage,
            'can_chips'   => ExpenseTypeService::canEditChips(),
            'ready'       => ExpenseTypeService::available(),
            'locked'      => ExpenseTypeService::LOCKED,
            'options'     => ExpenseTypeService::options($bu, $rcc),
        ];
        if ($request->boolean('manage')) {
            $data += ExpenseTypeService::managerList($bu, $rcc);
            if (!$canManage) {
                $data['orphans'] = [];
            }
            $data['last_change'] = $canManage ? ExpenseTypeService::lastUndoable() : null;
        }
        return response()->json($data);
    }

    /** Web pages: every active type with its scope (the page filters by BU + request type). */
    public function webList()
    {
        return response()->json([
            'success'    => true,
            'types'      => ExpenseTypeService::webPayload(),
            'can_manage' => ExpenseTypeService::canManage(),
        ]);
    }

    public function previewRename(Request $request, $id)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::previewRename((int) $id, (string) $request->input('name', '')));
    }

    public function rename(Request $request, $id)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::rename((int) $id, (string) $request->input('name', ''), $request->boolean('merge')));
    }

    public function setActive(Request $request, $id)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::setActive((int) $id, $request->boolean('active')));
    }

    public function reorder(Request $request)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        $ids = $request->input('ids', []);
        return $this->reply(ExpenseTypeService::reorder(is_array($ids) ? $ids : []));
    }

    public function setGroup(Request $request, $id)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::setGroup((int) $id, $request->input('group')));
    }

    public function mergeOrphan(Request $request)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::mergeOrphan(
            (string) $request->input('name', ''),
            (int) $request->input('into_id'),
            $request->input('business_unit_id')
        ));
    }

    public function undo(Request $request)
    {
        if ($deny = $this->deny()) {
            return $deny;
        }
        return $this->reply(ExpenseTypeService::undo((int) $request->input('undo_id')));
    }

    private function deny()
    {
        if (!ExpenseTypeService::canManage()) {
            return response()->json([
                'success' => false,
                'message' => 'Only Taimur can rename, reorder or switch off expense types.',
            ], 403);
        }
        return null;
    }

    private function reply(array $r)
    {
        $status = $r['status'] ?? ($r['ok'] ? 200 : 422);
        unset($r['status']);
        $r['success'] = (bool) ($r['ok'] ?? false);
        unset($r['ok']);
        return response()->json($r, $r['success'] ? 200 : $status);
    }
}
