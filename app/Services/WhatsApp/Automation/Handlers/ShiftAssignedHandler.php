<?php

namespace App\Services\WhatsApp\Automation\Handlers;

use App\Models\WhatsApp\AutomationModel;
use App\Services\WhatsApp\Automation\AutomationHandler;
use App\Models\Ops\UserShiftAssignmentModel;
use Illuminate\Support\Facades\DB;

/**
 * "WhatsApp the rider when their shift changes" (rule_key: shift_assigned).
 * Fires on `shift.assigned`, emitted post-commit from Ops\ShiftController's
 * assign/bulk-assign seam (the same place as the FCM push). Sends the operator's
 * approved template (default `shift_reminder`): "Dear {{1}}, your shift time is:
 * {{2}} please confirm" + a "confirm" quick-reply button. The rider's tap on that
 * button is handled inbound (WhatsAppService) and sets the SAME acknowledged_at
 * as the in-app confirm.
 *
 * Context: ['user_id' => int, 'assignment_id' => int]. Everything else is
 * re-resolved from fresh DB state at send time (dispatch is out-of-band, so the
 * row could have changed — e.g. already acknowledged in-app).
 *
 * Variables: {{1}} rider first name, {{2}} human shift description
 * ("Morning Shift 10:00–19:00 from Mon 14 Jul", "Manager Shift 11:00–20:00 on
 * Tue 8 Jul", "… for Wed 9 – Sun 13 Jul"). Sends once per assignment row.
 */
class ShiftAssignedHandler implements AutomationHandler
{
    public function dedupKey(array $context): string
    {
        $uid = (int) ($context['user_id'] ?? 0);
        $aid = (int) ($context['assignment_id'] ?? 0);
        // Include the TEMPLATE id: a same-day correction updates the assignment row
        // IN PLACE (same row id, new shift_template_id). Without the template in the
        // key, the corrected shift would be dedup-suppressed and the rider's phone
        // would still show — and confirm — the OLD shift. (The inbound confirm parser
        // only reads the leading "shift:{uid}:", so the extra segment is safe.)
        $row = $this->row($context);
        $tpl = $row ? (int) $row->shift_template_id : 0;
        return 'shift:' . $uid . ':' . $aid . ':t' . $tpl;
    }

    public function eligibility(array $context): ?string
    {
        $row = $this->row($context);
        if (!$row) {
            return 'assignment gone';
        }
        if ($row->acknowledged_at !== null) {
            return 'already confirmed (in-app)';
        }
        // Nothing to confirm for a change that's fully in the past.
        $to = $row->effective_to ? $row->effective_to->format('Y-m-d') : null;
        if ($to !== null && $to < now()->format('Y-m-d')) {
            return 'change already past';
        }
        if (!$row->shiftTemplate) {
            return 'no shift template';
        }
        if (!$this->recipientPhone($context)) {
            return 'no phone number';
        }
        return null;
    }

    /** Use the operator-chosen template (rule->template_name, default shift_reminder). */
    public function pickTemplate(array $context, AutomationModel $rule): ?string
    {
        return null;
    }

    public function bodyParams(array $context, AutomationModel $rule): array
    {
        $uid = (int) ($context['user_id'] ?? 0);
        $name = DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'there';
        $first = trim(explode(' ', trim((string) $name))[0]);
        return [$first !== '' ? $first : 'there', $this->describe($this->row($context))];
    }

    /** Text template — no media header. */
    public function headerParams(array $context, AutomationModel $rule): array
    {
        return [];
    }

    public function recipientPhone(array $context): ?string
    {
        $uid = (int) ($context['user_id'] ?? 0);
        $phone = DB::table('t_ops_rider_profile')->where('user_id', $uid)->value('phone');
        return $phone ? (string) $phone : null;
    }

    // ---- helpers ----------------------------------------------------------

    protected function row(array $context)
    {
        $aid = (int) ($context['assignment_id'] ?? 0);
        if ($aid <= 0) {
            return null;
        }
        return UserShiftAssignmentModel::with('shiftTemplate')->find($aid);
    }

    /**
     * Human, rider-friendly description of the change for {{2}}.
     * Bounded (temporary) rows read "on <day>" / "for <from> – <to>"; an
     * open-ended (primary) row reads "from <day>".
     */
    protected function describe($row): string
    {
        if (!$row || !$row->shiftTemplate) {
            return 'your updated shift';
        }
        $t = $row->shiftTemplate;
        $start = substr($t->shift_start, 0, 5);
        // Start-only shifts have no end time.
        $timeStr = $t->shift_end ? ($start . '–' . substr($t->shift_end, 0, 5)) : ('starts ' . $start);
        $base = $t->shift_name . ', ' . $timeStr;

        // Location for this change (resolved for the change's start date → assignment
        // location → rider default → primary). Shown in the same {{2}} variable.
        $uid = (int) ($row->user_id ?? 0);
        $date = $row->effective_from ? $row->effective_from->format('Y-m-d') : now()->format('Y-m-d');
        // 🔁 Weekly days (Oct-2026). The start date may itself be a weekly day, whose place
        //   is not this row's — so with weekly days, name the ROW's own place.
        $weekly = \App\Services\Ops\WeekdayPattern::parse($row->day_pattern ?? null);
        $loc = null;
        try {
            $svc = new \App\Services\ShiftResolutionService();
            $loc = $weekly
                ? ($svc->locationFor($uid, $row->location_id ? (int) $row->location_id : null)['location_name'] ?? null)
                : ($svc->getUserShift($uid, $date)['location_name'] ?? null);
        } catch (\Throwable $e) { /* location optional */ }
        if ($loc) {
            $base .= ' at ' . $loc;
        }
        // ⚠ ONE line — a WhatsApp template variable may not contain a newline or a tab.
        $weeklyTxt = $weekly ? ' (weekly days: ' . \App\Services\Ops\WeekdayPattern::summary($weekly) . ')' : '';

        $from = $row->effective_from ? $row->effective_from->format('D j M') : null;
        $to = $row->effective_to ? $row->effective_to->format('D j M') : null;
        if ($row->effective_to) { // bounded / temporary
            if ($from === $to) {
                return $base . ($to ? ' — ' . $to : '') . $weeklyTxt;
            }
            return $base . ' — ' . $from . ' to ' . $to . $weeklyTxt;
        }
        return $base . ($from ? ' — from ' . $from : '') . $weeklyTxt;
    }
}
