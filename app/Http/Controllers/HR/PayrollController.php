<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Services\HR\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    use \App\Http\Controllers\Concerns\TimeBankEndpoints;

    /** ⏱ Time-bank endpoints share the trait; the gate is the same as every payroll action. */
    protected function timeBankDeny(Request $request)
    {
        return $this->denyIfNotAllowed();
    }

    /** One key gates the whole screen: view, generate, set salaries, advances, pay. */
    private function denyIfNotAllowed()
    {
        $u = auth()->user();
        if (!$u || !$u->hasPermission('manage_payroll')) {
            return response()->json(['success' => false, 'message' => "You don't have access to payroll."], 403);
        }
        return null;
    }

    public function index()
    {
        $u = auth()->user();
        if (!$u || !$u->hasPermission('manage_payroll')) {
            abort(403, "You don't have access to payroll.");
        }
        return view('pages.payroll.index');
    }

    /**
     * Whether this user may VOID a salary advance (owner-level correction).
     *
     * Separate from `manage_payroll` on purpose: every payroll manager gives advances, but
     * un-doing one moves money back out of the books, so it is granted to the owner's role
     * only. Role-based — anyone later assigned that role inherits it automatically.
     */
    private function canVoidAdvance(): bool
    {
        $u = auth()->user();
        return $u && $u->hasPermission('void_salary_advance');
    }

    /**
     * Whether this user may VOID a custom-salary payment.
     *
     * Owner ruling Aug-2026: Taimur AND Shabib (roles 14 + 10), i.e. wider than the
     * advance void but still narrower than `manage_payroll`. Role-based, so it follows
     * the role rather than the person.
     */
    private function canVoidPayment(): bool
    {
        $u = auth()->user();
        return $u && $u->hasPermission('void_salary_payment');
    }

    /** Whether this manager may see/tag the Khaas business unit (gates the BU toggle). */
    private function canViewKhaas(): bool
    {
        try {
            $u = auth()->user();
            if (!$u) { return false; }
            $u->load(['roles.mobilePermissions']);
            $perms = method_exists($u, 'getMobilePermissions') ? $u->getMobilePermissions() : [];
            return in_array('access_khaas_mode', $perms, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The month grid + the accounts the pay modal needs. */
    public function data(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $month = $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $svc = new PayrollService();
            $rows = $svc->computeMonth($month);
        } catch (\Throwable $e) {
            \Log::error('Payroll data failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build payroll for this month.'], 500);
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'rows' => $rows,
            // Ships with the grid rather than as a second request: the rows above already
            // warmed the per-request compute cache, so this costs almost nothing here,
            // whereas a separate call would recompute the whole month from scratch.
            'leave_actions' => $svc->leaveActionsMonth($month),
            'funding' => $svc->fundingOptions(),
            'schedule_available' => $svc->scheduleTaggingAvailable(),
            // Minutes in one bonus-leave day. SHIFT_TARGET_HOURS is configurable, so the grid
            // must never hardcode 540 — it would quietly print a formula that isn't the rule.
            'ot_per_day' => (new \App\Services\HR\OvertimeService())->minutesPerBonusDay(),
            'ot_carry_on' => (new \App\Services\HR\OvertimeCarryService())->enabled(),
            'khaas_available' => $svc->khaasTaggingAvailable() && $this->canViewKhaas(),
            'khaas_bu_id' => $svc->khaasBuIdValue(),
            'can_void_advance' => $this->canVoidAdvance(),
            // Drives the "you have something waiting" banner + the strip card. Counts every
            // pending request, including staff who are not on this grid.
            'advance_summary' => $svc->pendingAdvanceSummary(),
            // Closed months whose absences nobody has decided about, and days still owed.
            // Drives the banner + card — this app has no push notifications, so the nag
            // lives where the person who can act on it already is.
            'absence_summary' => $svc->pendingAbsenceSummary($month),
        ]);
    }

    /**
     * The Employee tab — everything about ONE person, and nothing about anyone else.
     *
     * This is the privacy view: a manager can hand the laptop to an employee here without
     * exposing the roster, because the tab replaces the grid rather than overlaying it.
     * `user_id` is optional — with none, only the name picker is returned, so simply landing
     * on the tab shows no money at all.
     */
    public function employeeDetail(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'nullable|integer|exists:t_sys_user,id',
            'month'   => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $month = $request->month ?: now()->format('Y-m');
        $svc = new PayrollService();
        try {
            $out = ['success' => true, 'month' => $month, 'employees' => $svc->employeePickList($month)];
            if ($request->filled('user_id')) {
                $out += $svc->employeeDetail((int) $request->user_id, $month);
                $out['can_void_advance'] = $this->canVoidAdvance();
                $out['funding'] = $svc->fundingOptions();
                // A custom-schedule employee's card is the Custom tab's card, so it needs the
                // same flags customData() sends — otherwise landing here first would render it
                // with boot defaults (khata controls hidden, calendar mis-dated).
                $out['balance_available']  = $svc->balanceTrackingAvailable();
                $out['can_void_payment']   = $this->canVoidPayment();
                $out['today']              = now()->format('Y-m-d');
                $out['schedule_available'] = $svc->scheduleTaggingAvailable();
                $out['khaas_available']    = $svc->khaasTaggingAvailable() && $this->canViewKhaas();
                $out['khaas_bu_id']        = $svc->khaasBuIdValue();
            }
            return response()->json($out);
        } catch (\Throwable $e) {
            \Log::error('Employee payroll detail failed', [
                'user_id' => $request->user_id, 'month' => $month, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Could not load this employee.'], 500);
        }
    }

    /**
     * Forfeit an employee's carried overtime (owner ruling: carried minutes end only by being
     * used, or by someone deciding they are not valid). A reason is required — this removes
     * overtime the employee actually worked.
     */
    public function forfeitCarry(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'reason'  => 'required|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new \App\Services\HR\OvertimeCarryService())
            ->forfeitAll((int) $request->user_id, (string) $request->reason, (int) auth()->id());
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * Settle days that were PARKED in an earlier month — charge them, forgive them, or take
     * them out of the employee's own leave balance.
     *
     * These are the "decide later" half of parking. They are separate from the decide endpoint
     * because they act on a month that is already closed and often already paid, and they need
     * to say WHICH month's pay carries a charge.
     */
    public function settleAbsence(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],      // the month the days were parked in
            'action'  => 'required|in:charge,excuse,use_leave',
            // charge: which month's pay carries it (defaults to the one on screen) and the
            // amount, which the manager may override — the owner's call, not a formula's.
            'in_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'amount'   => 'nullable|numeric|min:0',
            'days'     => 'nullable|numeric|min:0.5',                 // use_leave: how many to take
            'note'     => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->settleParkedAbsence(
            (int) $request->user_id, (string) $request->month, (string) $request->action,
            $request->in_month, $request->amount === null ? null : (float) $request->amount,
            $request->days === null ? null : (float) $request->days,
            $request->note, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * Wave away the absence banner — per user, per month, exactly like the home-meter banner.
     * One manager dismissing it must not hide it from the other, and it comes back next month.
     */
    public function dismissAbsenceAlert(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), ['alert_key' => 'required|string|max:64']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal')) {
                DB::table('t_ops_alert_dismissal')->updateOrInsert(
                    ['user_id' => auth()->id(), 'alert_key' => (string) $request->alert_key],
                    ['dismissed_at' => now()]
                );
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not dismiss.'], 500);
        }
    }

    /** The Custom tab: date-range / weekly employees + their coverage for the month. */
    public function customData(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $month = $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $svc = new PayrollService();
            $rows = $svc->computeMonthCustom($month);
        } catch (\Throwable $e) {
            \Log::error('Payroll custom data failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not build the custom list for this month.'], 500);
        }

        return response()->json([
            'success' => true,
            'month' => $month,
            'month_label' => date('F Y', strtotime($month . '-01')),
            'rows' => $rows,
            'funding' => $svc->fundingOptions(),
            'schedule_available' => $svc->scheduleTaggingAvailable(),
            'khaas_available' => $svc->khaasTaggingAvailable() && $this->canViewKhaas(),
            'khaas_bu_id' => $svc->khaasBuIdValue(),
            'can_void_advance' => $this->canVoidAdvance(),
            'balance_available' => $svc->balanceTrackingAvailable(),
            'can_void_payment' => $this->canVoidPayment(),
            'today' => now()->format('Y-m-d'),
            // Same banner on this tab — a waiting request must not hide behind a tab choice.
            'advance_summary' => $svc->pendingAdvanceSummary(),
        ]);
    }

    // ── Custom running balance ("khata") ─────────────────────────────────────
    //
    // Same `manage_payroll` authority as every other money action on this screen;
    // voiding a payment additionally needs `void_salary_payment`.

    /** One month of an employee's calendar: days, payments and the running balance. */
    public function balanceCalendar(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'month'   => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $data = (new PayrollService())->balanceCalendar(
                (int) $request->user_id,
                (string) ($request->month ?: now()->format('Y-m'))
            );
        } catch (\Throwable $e) {
            \Log::error('Balance calendar failed', ['user_id' => $request->user_id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the calendar.'], 500);
        }
        return response()->json($data + ['can_void_payment' => $this->canVoidPayment(), 'today' => now()->format('Y-m-d')],
            !empty($data['success']) ? 200 : 422);
    }

    /** Start a running balance: anchor date + opening balance. */
    public function balanceEnable(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'    => 'required|integer|exists:t_sys_user,id',
            'start_date' => 'required|date',
            'opening'    => 'nullable|numeric|min:-100000000|max:100000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->enableBalanceTracking(
            (int) $request->user_id,
            date('Y-m-d', strtotime($request->start_date)),
            (float) ($request->opening ?? 0),
            (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Cross a day out (earns nothing) or restore it. Toggle. */
    public function balanceAbsence(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'date'    => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->toggleAbsence(
            (int) $request->user_id, date('Y-m-d', strtotime($request->date)), (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Record money handed to a tracked employee (a normal payroll payment + ledger row). */
    public function balancePayment(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'amount'  => 'required|numeric|min:1|max:100000000',
            'date'    => 'required|date',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'note'    => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }
        $res = (new PayrollService())->recordBalancePayment(
            (int) $request->user_id,
            (float) $request->amount,
            date('Y-m-d', strtotime($request->date)),
            [
                'funding'  => $request->funding,
                'bank_id'  => $request->bank_id ? (int) $request->bank_id : null,
                'note'     => $request->note,
                'actor_id' => (int) auth()->id(),
            ]
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Change a tracked employee's rate from a date the manager chooses. */
    public function balanceRate(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'        => 'required|integer|exists:t_sys_user,id',
            'rate'           => 'required|numeric|min:1|max:100000000',
            'effective_date' => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->changeTrackedRate(
            (int) $request->user_id, (float) $request->rate,
            date('Y-m-d', strtotime($request->effective_date)), (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Void a mistaken payment: reverses the ledger and drops it from the balance. */
    public function balanceVoidPayment(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        if (!$this->canVoidPayment()) {
            return response()->json(['success' => false, 'message' => "You don't have access to void a salary payment."], 403);
        }
        $v = Validator::make($request->all(), [
            'payment_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->voidBalancePayment(
            (int) $request->payment_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Live preview of a custom period's amount (days × rate, advances, attendance reference). */
    public function customPreview(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'start'   => 'required|date',
            'end'     => 'required|date',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $row = (new PayrollService())->computeCustomPeriod(
                (int) $request->user_id,
                date('Y-m-d', strtotime($request->start)),
                date('Y-m-d', strtotime($request->end))
            );
            return response()->json(['success' => true, 'row' => $row]);
        } catch (\Throwable $e) {
            \Log::error('Payroll custom preview failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not compute this period.'], 500);
        }
    }

    /** Pay a custom employee for a date range. */
    public function payCustom(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'start'   => 'required|date',
            'end'     => 'required|date',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'amount'  => 'nullable|numeric|min:0',
            'note'    => 'nullable|string|max:255',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }
        $res = (new PayrollService())->payCustomPeriod(
            (int) $request->user_id,
            date('Y-m-d', strtotime($request->start)),
            date('Y-m-d', strtotime($request->end)),
            [
                'funding'  => $request->funding,
                'bank_id'  => $request->bank_id ? (int) $request->bank_id : null,
                'amount'   => $request->has('amount') ? $request->amount : null,
                'note'     => $request->note,
                'actor_id' => (int) auth()->id(),
            ]
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** The "Staff Salaries" expense records behind a row's double-pay warning. */
    public function staffExpenseDetail(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        return response()->json([
            'success' => true,
            'records' => (new PayrollService())->staffExpenseDetail((int) $request->user_id, $request->month),
        ]);
    }

    /** Set an employee's pay schedule (monthly|custom) + rate type. */
    public function setSchedule(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        $v = Validator::make($request->all(), [
            'user_id'      => 'required|integer|exists:t_sys_user,id',
            'pay_schedule' => 'required|in:monthly,custom',
            'rate_type'    => 'nullable|in:daily,monthly',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->setSchedule(
            (int) $request->user_id, $request->pay_schedule, $request->rate_type, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Tag an employee's business unit (NF or Khaas). */
    public function setBusinessUnit(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) { return $deny; }
        // Only Khaas-visible managers may change BU tags at all — otherwise a
        // manager without Khaas access could still CLEAR someone's Khaas tag.
        if (!$this->canViewKhaas()) {
            return response()->json(['success' => false, 'message' => "You don't have access to the Khaas business unit."], 403);
        }
        $v = Validator::make($request->all(), [
            'user_id'          => 'required|integer|exists:t_sys_user,id',
            'business_unit_id' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->setBusinessUnit(
            (int) $request->user_id,
            $request->business_unit_id ? (int) $request->business_unit_id : null,
            (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Inline base-salary edit from the grid (audited). */
    public function setSalary(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'base_salary' => 'required|numeric|min:0|max:100000000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        try {
            $svc = new PayrollService();
            // A running-balance employee's rate needs the date it applies from, so send a
            // stale page to the right control instead of failing as a server error.
            if ($svc->isBalanceTracked((int) $request->user_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This employee is on a running balance — change the rate from their card so you can set the date it applies from.',
                ], 422);
            }
            $svc->setBaseSalary((int) $request->user_id, (float) $request->base_salary, (int) auth()->id());
            return response()->json(['success' => true, 'message' => 'Salary saved.']);
        } catch (\Throwable $e) {
            \Log::error('setSalary failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save the salary.'], 500);
        }
    }

    /** Give a mid-month advance from the payroll page (creates + posts a salary_advance). */
    public function giveAdvance(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:t_sys_user,id',
            'amount'  => 'required|numeric|min:1|max:100000000',
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'note'    => 'nullable|string|max:255',
            // The payroll month this advance is recovered from (the month the grid is showing),
            // and — when that month is in the past — the day the money really moved. The
            // service does the real validation (next-month ceiling, date inside the month,
            // month not already paid); this only rejects malformed input.
            //
            // ⚠ ARRAY syntax is required, not the usual 'nullable|regex:...' string: Laravel
            // splits a rule STRING on '|', and this pattern contains one inside (0[1-9]|1[0-2]).
            // As a string the rule is cut to 'regex:/^\d{4}-(0[1-9]' → preg_match() throws
            // "No ending delimiter" → a 500 HTML page → "not valid JSON" in the browser.
            'payroll_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'money_date'    => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->giveAdvance(
            (int) $request->user_id, (float) $request->amount, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, $request->note, (int) auth()->id(),
            $request->payroll_month, $request->money_date
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Advance requests from employees still awaiting a decision (the top card). */
    public function pendingRequests(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $svc = new PayrollService();
        $rows = $svc->pendingAdvanceRequests();
        return response()->json([
            'success' => true,
            'requests' => $rows,
            'count' => count($rows),
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'funding' => $svc->fundingOptions(),
        ]);
    }

    // ── Leave actions (overtime bonus / late penalty) ────────────────────────
    //
    // Read by BOTH the payroll Leave-actions panel and the Attendance month banner, and
    // gated by the same `manage_payroll` key on every call: a bonus/penalty leave changes
    // what an employee can take off and feeds the salary rules, so the authority to decide
    // it must not be wider on Attendance than it is on Payroll.

    /** The month's leave actions: what's recommended, what's decided, and why. */
    public function leaveActions(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $month = (string) $request->input('month', now()->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        try {
            $data = (new PayrollService())->leaveActionsMonth($month);
        } catch (\Throwable $e) {
            \Log::error('Leave actions failed', ['month' => $month, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the leave actions for this month.'], 500);
        }
        return response()->json(['success' => true] + $data);
    }

    /** Give/deduct or waive ONE employee's leave action for a month. */
    public function decideLeaveAction(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'user_id'  => 'required|integer',
            'month'    => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'kind'     => 'required|in:overtime,late_penalty,absence,time_bank',
            // Absence has three outcomes rather than two; the service rejects the wrong
            // pairing (e.g. kind=overtime with decision=park) with a readable message.
            'decision' => 'required|in:apply,waive,cut,park,excuse',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->decideLeaveAction(
            (int) $request->user_id, (string) $request->month,
            (string) $request->kind, (string) $request->decision, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Apply every still-pending recommendation for the month (never touches a decided one). */
    public function applyAllLeaveActions(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->applyAllPendingLeaveActions((string) $request->month, (int) auth()->id());
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * APPROVE an employee's advance request AND pay it now. Same money authority as
     * "+ advance" (both are gated by `manage_payroll`), so the funding account is chosen
     * here at approval time.
     */
    public function approveRequest(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'funding'    => 'required|in:cash,online',
            'bank_id'    => 'nullable|integer',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->approveAdvanceRequest(
            (int) $request->request_id, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** REJECT an employee's advance request (no money involved). */
    public function rejectRequest(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->rejectAdvanceRequest(
            (int) $request->request_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /**
     * VOID a wrongly-given advance: reverses its ledger entry through the balance engine and
     * cancels the request. Owner-only (`void_salary_advance`), on top of payroll access.
     */
    public function voidAdvance(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        if (!$this->canVoidAdvance()) {
            return response()->json(['success' => false, 'message' => 'Only the owner can void a salary advance.'], 403);
        }
        $v = Validator::make($request->all(), [
            'request_id' => 'required|integer',
            'reason'     => 'required|string|min:3|max:200',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        $res = (new PayrollService())->voidAdvance(
            (int) $request->request_id, (string) $request->reason, (int) auth()->id()
        );
        return response()->json($res, !empty($res['success']) ? 200 : 422);
    }

    /** Pay the selected rows. */
    public function pay(Request $request)
    {
        if ($deny = $this->denyIfNotAllowed()) return $deny;
        $v = Validator::make($request->all(), [
            'month'   => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'funding' => 'required|in:cash,online',
            'bank_id' => 'nullable|integer',
            'items'   => 'required|array|min:1',
            'items.*.user_id' => 'required|integer',
            'items.*.late_deduction' => 'nullable|numeric|min:0',
            'items.*.net_override' => 'nullable|numeric|min:0',
            'items.*.skip_overtime' => 'nullable|boolean',
            'items.*.skip_late_leave' => 'nullable|boolean',
            'items.*.defer_leave_actions' => 'nullable|boolean',
            // What to do when deductions exceed the salary. The manager answers this on the
            // pay dialog; it is never assumed silently.
            //
            // ⚠⚠ This list is the SERVICE's, not a hand-typed copy. It used to read
            // `in:carry,writeoff` — neither of which the dialog has ever sent — so picking
            // "don't apply this month's absent/late deductions" 422'd and failed the whole
            // batch. `carry` was a name from the design that was never built.
            'items.*.shortfall' => ['nullable', Rule::in(PayrollService::SHORTFALL_MODES)],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }
        if ($request->funding === 'online' && !$request->bank_id) {
            return response()->json(['success' => false, 'message' => 'Choose the bank you are paying from.'], 422);
        }

        $r = (new PayrollService())->payMany(
            $request->items, $request->month, $request->funding,
            $request->bank_id ? (int) $request->bank_id : null, (int) auth()->id()
        );
        $msg = $r['paid'] . ' paid (Rs ' . number_format($r['total']) . ')'
            . ($r['skipped'] ? ', ' . $r['skipped'] . ' skipped' : '')
            . ($r['failed'] ? ', ' . $r['failed'] . ' failed' : '') . '.';

        return response()->json([
            'success' => $r['paid'] > 0 || ($r['failed'] === 0),
            'message' => $msg,
            'paid' => $r['paid'], 'skipped' => $r['skipped'], 'failed' => $r['failed'],
            'total' => $r['total'], 'details' => $r['details'],
        ]);
    }
}
