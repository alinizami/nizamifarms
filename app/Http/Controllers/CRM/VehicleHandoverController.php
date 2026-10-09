<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\VehicleHandoverRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 🔁 Rider-initiated vehicle handover requests — every door, one controller.
 *
 * THREE AUDIENCES, THREE GATES:
 *   • the RIDER raises / cancels / reads his own          → self-scoped, no permission
 *   • the APPROVER lists and decides                       → `assign_vehicles`
 *   • both web and mobile use the SAME methods             → they cannot drift
 *
 * ⚠ The approver gate is deliberately the SAME key that governs the web fleet screen
 *   and the mobile handover (`assign_vehicles` — Shabib + Taimur today, Farooq
 *   addable by ticking his role). No new permission: approving a request is exactly
 *   the act of moving a machine between riders, which is what that key already means.
 *   A key that exists only in SQL is also invisible on the Roles screen and would
 *   never get ticked.
 */
class VehicleHandoverController extends Controller
{
    /** Meter photos are phone-camera sized; same ceiling the vehicle photos use. */
    private const MAX_PHOTO_KB = 8192;

    private function svc(): VehicleHandoverRequestService
    {
        return new VehicleHandoverRequestService();
    }

    /**
     * May this user decide requests? Mirrors `VehicleController::canManage()` exactly
     * — including the read-only refusal. `hasPermission()` reads the web permission
     * table and behaves identically under Sanctum, so one grant governs the web page
     * and the phone and nobody can be allowed on one and refused on the other.
     */
    private function canApprove(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) $u->hasPermission('assign_vehicles');
    }

    // =================================================================
    // RIDER SIDE — self-scoped, needs no permission key
    // =================================================================

    /** What he holds, what he could ask for, and what he would get back. */
    public function options(Request $request)
    {
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);
        return response()->json(['success' => true] + $this->svc()->options((int) $u->id));
    }

    /** His own open request — the "⏳ waiting for approval" state on his card. */
    public function mine(Request $request)
    {
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);
        return response()->json([
            'success' => true,
            'request' => $this->svc()->openFor((int) $u->id),
        ]);
    }

    public function raise(Request $request)
    {
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);

        $data = $request->validate([
            'vehicle_id' => 'required|integer',
            'direction'  => 'nullable|in:take,return',
            // Soft-validated exactly like every other handover odometer in this system:
            // a number, and nothing more. The reading describes something that already
            // happened; the approver is shown a plausibility hint and decides.
            'meter'      => 'nullable|integer|min:0|max:9999999',
            // ⭐ 7-Oct: the CLOSING reading of the company machine he is holding — only on a
            //   company → company swap. `meter` stays the reading of the machine he asks about.
            'close_meter'=> 'nullable|integer|min:0|max:9999999',
            'note'       => 'nullable|string|max:255',
            'photo'      => 'nullable|image|max:' . self::MAX_PHOTO_KB,
        ]);

        // Stored best-effort BEFORE the request row so the row can carry the path;
        // a failed upload must not cost him the request unless the photo is required.
        $photoPath = null;
        if ($request->hasFile('photo')) {
            try {
                $photoPath = $request->file('photo')
                    ->store('vehicle-handover/' . date('Y-m'), 'public');
            } catch (\Throwable $e) {
                Log::warning('handover photo not stored', ['error' => $e->getMessage()]);
            }
        }

        $res = $this->svc()->raise(
            (int) $u->id,
            $data['direction'] ?? 'take',
            (int) $data['vehicle_id'],
            isset($data['meter']) ? (int) $data['meter'] : null,
            $data['note'] ?? null,
            $photoPath,
            isset($data['close_meter']) ? (int) $data['close_meter'] : null
        );

        if (!($res['ok'] ?? false)) {
            // Don't leave an orphan file behind for a request that was refused.
            if ($photoPath) { try { Storage::disk('public')->delete($photoPath); } catch (\Throwable $e) {} }
            // `meter_missing` names the box the phone should point at ('meter' | 'close_meter').
            return response()->json(['success' => false, 'message' => $res['message']]
                + array_intersect_key($res, ['meter_missing' => 1]), 422);
        }
        return response()->json(['success' => true] + $res);
    }

    public function cancel(Request $request, $id)
    {
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);

        $res = $this->svc()->cancel((int) $id, (int) $u->id);
        return response()->json(['success' => $res['ok'] ?? false, 'message' => $res['message']],
                                ($res['ok'] ?? false) ? 200 : 422);
    }

    // =================================================================
    // APPROVER SIDE — `assign_vehicles`
    // =================================================================

    /**
     * The open set. ONE query behind the mobile store banner, the web orders-page
     * banner and the fleet strip.
     *
     * ⚠ Returns an EMPTY LIST rather than 403 for someone without the right, so a
     *   banner poll on a screen a non-approver can open never spams the log with
     *   refusals. Deciding is what is gated, and it is gated hard.
     */
    public function pending(Request $request)
    {
        if (!$this->canApprove()) {
            return response()->json(['success' => true, 'requests' => [], 'can_approve' => false]);
        }
        return response()->json([
            'success'     => true,
            'can_approve' => true,
            'requests'    => $this->svc()->live((int) $request->query('limit', 25)),
        ]);
    }

    public function approve(Request $request, $id)
    {
        return $this->decide($request, (int) $id, true);
    }

    public function reject(Request $request, $id)
    {
        return $this->decide($request, (int) $id, false);
    }

    private function decide(Request $request, int $id, bool $approve)
    {
        if (!$this->canApprove()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to approve handovers'], 403);
        }
        $data = $request->validate([
            'give_back_vehicle_id' => 'nullable|integer',
            'give_back_none'       => 'nullable|boolean',
            'meter'                => 'nullable|integer|min:0|max:9999999',
            // ⭐ 7-Oct: the closing reading of the company machine he steps OFF on a take.
            'close_meter'          => 'nullable|integer|min:0|max:9999999',
            'displaced_action'     => 'nullable|in:none,own,vehicle',
            'displaced_vehicle_id' => 'nullable|integer',
            'displaced_meter'      => 'nullable|integer|min:0|max:9999999',
            'note'                 => 'nullable|string|max:255',
        ]);

        $res = $this->svc()->decide($id, $approve, (int) auth()->id(), $data);
        if (!($res['ok'] ?? false)) {
            // ⭐ `meter_missing` ('meter' | 'close_meter') + `meter_vehicle_id` let the banner ask
            //   for exactly that one reading and retry, instead of a dead-end alert.
            return response()->json(['success' => false, 'message' => $res['message']]
                + array_intersect_key($res, ['meter_missing' => 1, 'meter_vehicle_id' => 1]), 422);
        }
        return response()->json(['success' => true] + $res);
    }
}
