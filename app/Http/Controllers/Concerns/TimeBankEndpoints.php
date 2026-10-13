<?php

namespace App\Http\Controllers\Concerns;

use App\Services\HR\TimeBankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ⏱ Time-bank endpoints — ONE implementation shared by the web payroll controller and the
 * mobile payroll API, so the two surfaces can never accept different inputs. Each controller
 * supplies its own permission gate (`timeBankDeny`) — both require manage_payroll.
 */
trait TimeBankEndpoints
{
    abstract protected function timeBankDeny(Request $request);

    private function tbMonth($m): ?string
    {
        return (is_string($m) && preg_match('/^\d{4}-\d{2}$/', $m)) ? $m : null;
    }

    private function tbReply(array $res)
    {
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** GET ?user_id&month[&cover=0|1][&waive=minutes][&redecide=1] — the card, recomputed live. */
    public function timeBankPreview(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        $month = $this->tbMonth($request->input('month'));
        $uid = (int) $request->input('user_id');
        if (!$month || $uid <= 0) {
            return response()->json(['success' => false, 'message' => 'user_id and month (YYYY-MM) are required.'], 422);
        }
        $opts = [];
        if ($request->has('cover')) { $opts['cover'] = filter_var($request->input('cover'), FILTER_VALIDATE_BOOLEAN); }
        if ($request->filled('waive')) { $opts['waive'] = max(0, (int) $request->input('waive')); }
        if ($request->boolean('redecide')) { $opts['redecide'] = true; }
        try {
            return response()->json(['success' => true, 'statement' => (new TimeBankService())->month($uid, $month, $opts)]);
        } catch (\Throwable $e) {
            \Log::error('TimeBank preview failed', ['user_id' => $uid, 'month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not read this month.'], 500);
        }
    }

    public function timeBankDecide(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id'      => 'required|integer',
            'month'        => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'cover'        => 'nullable|boolean',
            'late_choice'  => 'nullable|in:leave,leave_salary,salary,carry',
            'ot_choice'    => 'nullable|in:give,carry,skip',
            'waive'        => 'nullable|integer|min:0|max:20000',
            'waive_reason' => 'nullable|string|max:255',
            'expect'       => 'nullable|string|max:64',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new TimeBankService())->decide((int) $request->user_id, (string) $request->month, [
            'cover'        => $request->has('cover') ? $request->boolean('cover') : true,
            'late_choice'  => $request->input('late_choice'),
            'ot_choice'    => $request->input('ot_choice'),
            'waive'        => (int) $request->input('waive', 0),
            'waive_reason' => (string) $request->input('waive_reason', ''),
            'expect'       => $request->input('expect'),
        ], (int) $request->user()->id);
        return $this->tbReply($res);
    }

    public function timeBankUndo(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        $month = $this->tbMonth($request->input('month'));
        if (!$month || (int) $request->input('user_id') <= 0) {
            return response()->json(['success' => false, 'message' => 'user_id and month are required.'], 422);
        }
        return $this->tbReply((new TimeBankService())->undo((int) $request->input('user_id'), $month, (int) $request->user()->id));
    }

    /** POST user_id, kind (late|ot), minutes (signed), reason — Taimur's manual tool. */
    public function timeBankAdjust(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'kind'    => 'required|in:late,ot',
            'minutes' => 'required|integer|not_in:0',
            'reason'  => 'required|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        return $this->tbReply((new TimeBankService())->adjust((int) $request->user_id, (string) $request->kind,
            (int) $request->minutes, (string) $request->reason, (int) $request->user()->id));
    }

    public function timeBankRemoveAdjust(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        return $this->tbReply((new TimeBankService())->removeAdjust((int) $request->input('user_id'),
            (int) $request->input('adjust_id'), (int) $request->user()->id));
    }

    /** GET ?user_id — decided months + adjustments for the Employee tab / phone detail. */
    public function timeBankHistory(Request $request)
    {
        if ($deny = $this->timeBankDeny($request)) return $deny;
        $uid = (int) $request->input('user_id');
        if ($uid <= 0) { return response()->json(['success' => false, 'message' => 'user_id is required.'], 422); }
        return response()->json(['success' => true] + (new TimeBankService())->history($uid));
    }
}
