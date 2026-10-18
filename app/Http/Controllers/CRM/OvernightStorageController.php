<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CRM\OvernightItemModel;
use App\Models\CRM\OvernightLogModel;
use App\Models\CRM\ProductModel;
use Carbon\Carbon;

/**
 * Overnight Storage — standalone chiller/freezer packet tracker for the NF store team.
 *
 * Regular NF products only (business_unit_id = 1). One row per packet, entered by
 * scanning the Czerlop scale barcode (or manual add). An append-only log
 * (t_crm_overnight_log) drives history and the per-day closing summary.
 *
 * DELIBERATELY touches NO other inventory quantity anywhere — this is a physical
 * placement log, not a stock layer.
 *
 * Dual-mounted: web routes (routes/web.php, prefix 'overnight') and mobile API
 * (routes/api.php, prefix 'overnight' under auth:sanctum) share these methods.
 */
class OvernightStorageController extends Controller
{
    private const SECTIONS = ['chiller', 'freezer'];

    /**
     * Only these log actions move stock between sections. Verify rows must never
     * reach the balance identity in getDailySummary() — see the note there.
     */
    private const MOVEMENT_ACTIONS = ['in', 'move', 'out'];

    /**
     * Check access via the mobile permission system (same pattern as KhaasController::hasKhaasAccess) —
     * one permission gates the web page, the mobile screen, and every endpoint.
     */
    private function hasOvernightAccess(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        return $user->hasMobilePermission('access_overnight_storage');
    }

    /**
     * Verifying (physically confirming the listed packets) is a manager-level right,
     * separate from the day-to-day scan/move/take-out access — a staffer who enters
     * items should not be able to sign them off as checked.
     */
    private function canVerify(): bool
    {
        $user = auth()->user();
        if (!$user) return false;

        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        return $user->hasMobilePermission('verify_overnight_storage');
    }

    private function denyJson()
    {
        return response()->json(['success' => false, 'message' => 'You do not have access to Overnight Storage.'], 403);
    }

    /**
     * PHP port of NizamiFarmsMobile/src/utils/barcodeDecode.js — the server re-decode is
     * authoritative for weight + PLU on scanned entries, so a client bug can never store
     * a weight that disagrees with the label.
     *
     * Format: digit 1 = in-store flag '2', digits 2-7 = Czerlop PLU,
     * digits 8-12 = weight in grams, digit 13 = EAN-13 check digit.
     */
    private function decodeWeightBarcode(?string $raw): ?array
    {
        // Sep-2026: the implementation moved to the shared decoder so Storage/Supplies
        // could reuse it instead of becoming a third copy. Same rules, same return
        // shape, byte-for-byte — this stays as a thin delegate so every call site in
        // this controller is untouched.
        return app(\App\Services\CRM\WeightBarcodeDecoder::class)->decode($raw);
    }

    /**
     * Stored items grouped per section, with per-section totals. Shared by the web
     * page (server-rendered) and getItems (JSON) so both surfaces always agree.
     */
    private function buildSections(): array
    {
        $items = OvernightItemModel::where('status', 'stored')
            ->orderBy('entered_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $userIds = $items->pluck('entered_by')
            ->merge($items->pluck('verified_by'))
            ->filter()->unique()->values()->toArray();
        $userNames = empty($userIds)
            ? []
            : DB::table('t_sys_user')->whereIn('id', $userIds)->pluck('fullname', 'id')->toArray();

        // A move NEVER rewrites entered_at — a packet keeps its original entry
        // date/time for life, which is what identifies the batch. The latest move is
        // surfaced separately so the row can also say where it came from and when.
        $lastMove = [];
        $itemIds = $items->pluck('id')->toArray();
        if (!empty($itemIds)) {
            foreach (OvernightLogModel::where('action', 'move')
                ->whereIn('item_id', $itemIds)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['item_id', 'from_section', 'to_section', 'created_at']) as $mv) {
                $lastMove[$mv->item_id] = $mv; // ascending, so the last write wins
            }
        }

        $sections = [];
        foreach (self::SECTIONS as $sec) {
            $sections[$sec] = [
                'count' => 0,
                'total_kg' => 0.0,
                'total_pcs' => 0.0,
                // "Verified" decays: a packet confirmed 3 days ago is not confirmed today,
                // so the headline number is always TODAY's coverage.
                'verified_today_count' => 0,
                'items' => [],
            ];
        }

        $today = Carbon::today();
        foreach ($items as $item) {
            $sec = $item->section;
            if (!isset($sections[$sec])) {
                continue;
            }
            $enteredAt = $item->entered_at ? Carbon::parse($item->entered_at) : null;
            $verifiedAt = $item->verified_at ? Carbon::parse($item->verified_at) : null;
            $verifiedToday = $verifiedAt ? $verifiedAt->isToday() : false;

            $sections[$sec]['count']++;
            if ($verifiedToday) {
                $sections[$sec]['verified_today_count']++;
            }
            if ($item->unit === 'pcs') {
                $sections[$sec]['total_pcs'] += (float) $item->quantity;
            } else {
                $sections[$sec]['total_kg'] += (float) $item->quantity;
            }
            $sections[$sec]['items'][] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'plu' => $item->plu,
                'barcode' => $item->barcode,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'source' => $item->source,
                'entered_at' => $enteredAt ? $enteredAt->toDateTimeString() : null,
                'entered_at_formatted' => $enteredAt ? $enteredAt->format('M d, h:i A') : '',
                'entered_by_name' => $item->entered_by
                    ? ($userNames[$item->entered_by] ?? ('User #' . $item->entered_by))
                    : '',
                'age_days' => $enteredAt
                    ? (int) abs($enteredAt->copy()->startOfDay()->diffInDays($today))
                    : 0,
                'verified_at' => $verifiedAt ? $verifiedAt->toDateTimeString() : null,
                'verified_at_formatted' => $verifiedAt ? $verifiedAt->format('M d, h:i A') : '',
                'verified_by_name' => $item->verified_by
                    ? ($userNames[$item->verified_by] ?? ('User #' . $item->verified_by))
                    : '',
                'verified_today' => $verifiedToday,
                'moved_from' => isset($lastMove[$item->id]) ? $lastMove[$item->id]->from_section : null,
                'moved_at_formatted' => isset($lastMove[$item->id]) && $lastMove[$item->id]->created_at
                    ? Carbon::parse($lastMove[$item->id]->created_at)->format('M d, h:i A')
                    : '',
            ];
        }

        // Latest verification per section (from the append-only log), so the page can
        // say "checked by X at 6:15 PM" even when items have changed since.
        $lastVerifications = [];
        // Ordered by TIME, not id — "latest verification" must mean latest by clock even
        // if a row is ever written with a backdated timestamp.
        $verifRows = OvernightLogModel::where('action', 'verify')
            ->whereIn('section', self::SECTIONS)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(40)
            ->get();
        $verifUserIds = $verifRows->pluck('created_by')->filter()->unique()->values()->toArray();
        $verifUserNames = empty($verifUserIds)
            ? []
            : DB::table('t_sys_user')->whereIn('id', $verifUserIds)->pluck('fullname', 'id')->toArray();
        foreach ($verifRows as $row) {
            if (isset($lastVerifications[$row->section])) {
                continue; // rows are newest-first, so the first hit per section wins
            }
            $at = $row->created_at ? Carbon::parse($row->created_at) : null;
            $lastVerifications[$row->section] = [
                'by_name' => $row->created_by
                    ? ($verifUserNames[$row->created_by] ?? ('User #' . $row->created_by))
                    : 'System',
                'at' => $at ? $at->toDateTimeString() : null,
                'at_formatted' => $at ? $at->format('M d, h:i A') : '',
                'at_ago' => $at ? $at->diffForHumans() : '',
                'item_count' => (int) $row->item_count,
                'total_kg' => round((float) $row->quantity, 3),
                'is_today' => $at ? $at->isToday() : false,
            ];
        }

        foreach ($sections as $sec => &$s) {
            $s['total_kg'] = round($s['total_kg'], 3);
            $s['total_pcs'] = round($s['total_pcs'], 3);
            $s['unverified_today_count'] = $s['count'] - $s['verified_today_count'];
            $s['all_verified_today'] = $s['count'] > 0 && $s['unverified_today_count'] === 0;
            $s['last_verification'] = $lastVerifications[$sec] ?? null;
        }
        unset($s);

        return $sections;
    }

    /**
     * Web page. ?tab=current|history
     */
    public function index(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            abort(403, 'You do not have access to Overnight Storage.');
        }

        $activeTab = $request->get('tab', 'current');
        if (!in_array($activeTab, ['current', 'history'], true)) {
            $activeTab = 'current';
        }

        return view('pages.overnight.index', [
            'activeTab' => $activeTab,
            'sections' => $this->buildSections(),
            'frozen' => $this->buildFrozenPanel(),
            'canVerify' => $this->canVerify(),
        ]);
    }

    /**
     * Frozen (BU-2) products sitting in the freezer — READ-ONLY.
     *
     * ⭐⭐ These are NOT overnight packets and must never become any. Their figure IS
     *     the live store inventory (variant.inventory_quantity), already maintained by
     *     the transfer-accept (+) and prepare/deduct (−) flows, so the freezer figure
     *     and the inventory figure are the same number by construction — nothing to
     *     backfill, nothing to reconcile, and no way for the two to drift.
     *
     * ⚠️ Deliberately returned SEPARATELY from buildSections(): the section arrays feed
     *    the balance identity, the verify flow and take-out. Frozen entering any of
     *    those would corrupt a ledger it does not belong to. It is display-only here,
     *    with no checkbox, no move, no take-out and no verify.
     *
     * Managing this stock stays where it already lives — Khaas transfers, the prepare
     * flow, and the store adjustment screen, each with its own permission and audit.
     */
    private function buildFrozenPanel(): array
    {
        $rows = collect(app(\App\Services\CRM\OvernightStockService::class)->catalogRows())
            ->filter(fn ($row) => ($row['source'] ?? null) === \App\Services\CRM\OvernightStockService::SOURCE_FROZEN)
            ->map(fn ($row) => [
                'product_id' => $row['id'],
                'name' => $row['name'],
                'packets' => (int) $row['freezer']['packets'],
                'warning' => $row['warning'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'products' => $rows,
            'product_count' => count($rows),
            'total_packs' => array_sum(array_column($rows, 'packets')),
        ];
    }

    /**
     * GET items — current stored items grouped by section.
     */
    public function getItems(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        return response()->json([
            'success' => true,
            'sections' => $this->buildSections(),
            // ⚠️ Separate from `sections` on purpose — read-only, and never part of the
            //    packet balance, verify or take-out flows. See buildFrozenPanel().
            'frozen' => $this->buildFrozenPanel(),
            // Sent so the client reflects a permission change without waiting for
            // its cached permission list to refresh.
            'can_verify' => $this->canVerify(),
        ]);
    }

    /**
     * GET products — PLU-tagged NF products for client-side barcode resolution.
     * ?all=1 additionally returns every active NF product for the manual-add picker.
     * Regular NF products only (business_unit_id = 1) — Khaas/Frozen products excluded.
     */
    public function getProductsMap(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $nfBuId = ProductModel::DEFAULT_BUSINESS_UNIT_ID;

        $products = ProductModel::whereNotNull('czerlop_product_id')
            ->where('is_active', true)
            ->where('business_unit_id', $nfBuId)
            ->orderBy('title')
            ->get(['id', 'title', 'czerlop_product_id', 'weight_factor'])
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->title,
                    'plu' => (int) $p->czerlop_product_id,
                    'weight_factor' => (float) ($p->weight_factor ?? 1.0),
                ];
            })
            ->values();

        $payload = ['success' => true, 'products' => $products];

        if ($request->boolean('all')) {
            // 📦 bundles are not physical packets — nothing to put in the chiller
            $payload['manual_products'] = \App\Services\CRM\ProductBundle::excludeBundles(
                ProductModel::where('is_active', true)->where('business_unit_id', $nfBuId)
            )
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(function ($p) {
                    return ['id' => $p->id, 'name' => $p->title];
                })
                ->values();
        }

        return response()->json($payload);
    }

    /**
     * POST items — batch add (one row per packet). All-or-nothing so a failed save
     * never splits a scan session in half.
     */
    public function addItems(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $validated = $request->validate([
            'section' => 'required|in:chiller,freezer',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'items.*.quantity' => 'required|numeric|min:0.001|max:999',
            'items.*.unit' => 'nullable|in:kg,pcs',
            'items.*.barcode' => 'nullable|digits:13',
            'items.*.source' => 'required|in:scan,manual',
        ]);

        $section = $validated['section'];
        $userId = auth()->id();

        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();
        $products = ProductModel::whereIn('id', $productIds)
            ->get(['id', 'title', 'czerlop_product_id', 'business_unit_id'])
            ->keyBy('id');

        // 🔒 Frozen (BU-2) products are tracked by STORE INVENTORY, never by overnight
        //    packets. Their freezer figure is DERIVED live from
        //    variant.inventory_quantity (see OvernightStockService::loadFrozenInventory),
        //    which the transfer-accept and prepare/deduct flows already maintain — so a
        //    stored packet here would count the same physical pack TWICE, and it would
        //    go stale the moment that pack is sold (overnight touches no other inventory).
        //    ⭐ The product picker already excludes BU-2, but that is UI-only: this rail
        //    is what makes the no-double-count rule impossible to violate from a stale
        //    APK, a widened picker, or a direct API call.
        $frozen = $products->first(function ($product) {
            return (int) $product->business_unit_id === \App\Services\CRM\OvernightStockService::FROZEN_BUSINESS_UNIT_ID;
        });
        if ($frozen) {
            return response()->json([
                'success' => false,
                'message' => '"' . $frozen->title . '" is a Frozen item. Frozen stock is tracked by '
                    . 'store inventory, not by overnight packets — it already shows in the freezer '
                    . 'automatically.',
            ], 422);
        }

        // Validate every barcode BEFORE the transaction so the whole batch fails fast.
        $decodedByIndex = [];
        foreach ($validated['items'] as $idx => $in) {
            if (empty($in['barcode'])) {
                continue;
            }
            $decoded = $this->decodeWeightBarcode($in['barcode']);
            if (!$decoded) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item ' . ($idx + 1) . ': not a valid scale barcode.',
                ], 422);
            }
            $product = $products->get($in['product_id']);
            if ($product && $product->czerlop_product_id !== null
                && (int) $product->czerlop_product_id !== $decoded['plu']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item ' . ($idx + 1) . ': barcode PLU ' . $decoded['plu']
                        . ' does not match the selected product.',
                ], 422);
            }
            $decodedByIndex[$idx] = $decoded;
        }

        $createdCount = 0;
        DB::transaction(function () use ($validated, $section, $userId, $products, $decodedByIndex, &$createdCount) {
            foreach ($validated['items'] as $idx => $in) {
                $product = $products->get($in['product_id']);
                $decoded = $decodedByIndex[$idx] ?? null;
                // Server re-decode is authoritative for scanned weight + PLU.
                $quantity = $decoded ? $decoded['weight_kg'] : (float) $in['quantity'];
                $unit = $decoded ? 'kg' : ($in['unit'] ?? 'kg');

                $item = OvernightItemModel::create([
                    'product_id' => $in['product_id'],
                    'product_name' => $product ? $product->title : ('Product #' . $in['product_id']),
                    'plu' => $decoded ? $decoded['plu'] : null,
                    'barcode' => $decoded ? $decoded['raw'] : null,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'section' => $section,
                    'status' => 'stored',
                    'source' => $in['source'],
                    'entered_at' => now(),
                    'entered_by' => $userId,
                ]);

                OvernightLogModel::create([
                    'item_id' => $item->id,
                    'action' => 'in',
                    'from_section' => null,
                    'to_section' => $section,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'source' => $in['source'],
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                $createdCount++;
            }
        });

        return response()->json([
            'success' => true,
            'created_count' => $createdCount,
            'message' => $createdCount . ' item(s) added to ' . ucfirst($section) . '.',
        ]);
    }

    /**
     * POST move — bulk move between sections. Items already in the target section
     * are skipped (idempotent), so a double-tap can never double-log.
     */
    public function moveItems(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $validated = $request->validate([
            'item_ids' => 'required|array|min:1|max:200',
            'item_ids.*' => 'integer',
            'to_section' => 'required|in:chiller,freezer',
        ]);

        $toSection = $validated['to_section'];
        $userId = auth()->id();
        $moved = 0;
        $skipped = 0;

        DB::transaction(function () use ($validated, $toSection, $userId, &$moved, &$skipped) {
            $items = OvernightItemModel::whereIn('id', $validated['item_ids'])
                ->where('status', 'stored')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($item->section === $toSection) {
                    $skipped++;
                    continue;
                }
                $from = $item->section;
                $item->section = $toSection;
                $item->save();

                OvernightLogModel::create([
                    'item_id' => $item->id,
                    'action' => 'move',
                    'from_section' => $from,
                    'to_section' => $toSection,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                $moved++;
            }
        });

        return response()->json([
            'success' => true,
            'moved_count' => $moved,
            'skipped_count' => $skipped,
            'message' => $moved . ' item(s) moved to ' . ucfirst($toSection) . '.',
        ]);
    }

    /**
     * POST take-out — bulk take out for use. Only 'stored' rows are affected, so a
     * concurrent double-tap is a 0-row success, never a duplicate log entry.
     *
     * `source` records HOW it was taken out: 'scan' (the packet's barcode was scanned
     * on the mobile app) or 'manual' (picked from the list). The history shows both
     * who did it and which way.
     *
     * ⭐ ACCEPTS A WHOLE `section` AS WELL AS `item_ids` (owner ask, Aug-16 — the
     *    "Empty Chiller" button). The team was already doing this by hand every
     *    morning: select all, take out. One named action is fewer taps and, more
     *    importantly, ATOMIC — a list-driven bulk take-out is capped at 200 ids and
     *    posts whatever the screen happened to be showing, so a packet added by
     *    someone else between the load and the tap would silently survive the
     *    "empty". A section take-out reads the rows inside the transaction, so it
     *    empties what is ACTUALLY there.
     *
     * ⚠ Each row is still logged individually — the history keeps naming every packet
     *   and its section, so an emptied chiller reads exactly like the hand-picked
     *   version it replaces. Nothing about the audit trail changes.
     */
    public function takeOutItems(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $validated = $request->validate([
            'item_ids' => 'nullable|array|min:1|max:200',
            'item_ids.*' => 'integer',
            'section' => 'nullable|in:chiller,freezer',
            'source' => 'nullable|in:scan,manual',
        ]);
        if (empty($validated['item_ids']) && empty($validated['section'])) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to take out — pass item_ids or a section.',
            ], 422);
        }
        $source = $validated['source'] ?? 'manual';

        $userId = auth()->id();
        $takenOut = 0;
        $takenOutItems = [];

        DB::transaction(function () use ($validated, $userId, $source, &$takenOut, &$takenOutItems) {
            $query = OvernightItemModel::where('status', 'stored');
            if (!empty($validated['item_ids'])) {
                $query->whereIn('id', $validated['item_ids']);
            }
            if (!empty($validated['section'])) {
                $query->where('section', $validated['section']);
            }
            $items = $query->lockForUpdate()->get();

            foreach ($items as $item) {
                $from = $item->section;
                $enteredAt = $item->entered_at ? Carbon::parse($item->entered_at) : null;
                $item->status = 'taken_out';
                $item->taken_out_at = now();
                $item->taken_out_by = $userId;
                $item->save();

                OvernightLogModel::create([
                    'item_id' => $item->id,
                    'action' => 'out',
                    'from_section' => $from,
                    'to_section' => null,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'source' => $source,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                // Echo the batch that left, so the scan flow can confirm exactly which
                // packet (by its original entry time) was removed.
                $takenOutItems[] = [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'section' => $from,
                    'entered_at_formatted' => $enteredAt ? $enteredAt->format('M d, h:i A') : '',
                ];

                $takenOut++;
            }
        });

        return response()->json([
            'success' => true,
            'taken_out_count' => $takenOut,
            'source' => $source,
            'items' => $takenOutItems,
            // Name the section when one was emptied — "Chiller emptied — 7 item(s)
            // taken out" is the confirmation the person actually asked for.
            'message' => !empty($validated['section'])
                ? ($takenOut === 0
                    ? 'The ' . $validated['section'] . ' was already empty.'
                    : ucfirst($validated['section']) . ' emptied — ' . $takenOut . ' item(s) taken out.')
                : $takenOut . ' item(s) taken out.',
        ]);
    }

    /**
     * POST verify — a manager physically confirms the listed packets.
     *
     * Accepts either explicit `item_ids` (a selection) or a whole `section`
     * ("verify everything in the chiller"). Stamps verified_at/by on each packet
     * and writes ONE log row per section verified, so the history reads as
     * "Taimur verified the Chiller — 12 items" rather than 12 separate lines.
     *
     * Verifying is deliberately repeatable: re-verifying refreshes the stamp.
     */
    public function verifyItems(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }
        if (!$this->canVerify()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to verify overnight quantities.',
            ], 403);
        }

        $validated = $request->validate([
            'item_ids' => 'nullable|array|max:500',
            'item_ids.*' => 'integer',
            'section' => 'nullable|in:chiller,freezer',
        ]);

        if (empty($validated['item_ids']) && empty($validated['section'])) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to verify — pass item_ids or a section.',
            ], 422);
        }

        $userId = auth()->id();
        $verifiedCount = 0;
        $perSection = [];

        DB::transaction(function () use ($validated, $userId, &$verifiedCount, &$perSection) {
            $query = OvernightItemModel::where('status', 'stored');
            if (!empty($validated['item_ids'])) {
                $query->whereIn('id', $validated['item_ids']);
            }
            if (!empty($validated['section'])) {
                $query->where('section', $validated['section']);
            }
            $items = $query->lockForUpdate()->get();

            if ($items->isEmpty()) {
                return;
            }

            $now = now();
            foreach ($items as $item) {
                $item->verified_at = $now;
                $item->verified_by = $userId;
                $item->save();
                $verifiedCount++;
            }

            // One log row per section touched.
            foreach ($items->groupBy('section') as $section => $group) {
                $totalKg = $group->where('unit', 'kg')->sum(function ($i) {
                    return (float) $i->quantity;
                });
                OvernightLogModel::create([
                    'item_id' => null,
                    'action' => 'verify',
                    'from_section' => null,   // ⚠ must stay NULL — feeds the balance identity
                    'to_section' => null,     // ⚠ must stay NULL — feeds the balance identity
                    'section' => $section,
                    'item_count' => $group->count(),
                    'product_id' => null,
                    'product_name' => null,
                    'quantity' => round($totalKg, 3),
                    'unit' => 'kg',
                    'created_by' => $userId,
                    'created_at' => $now,
                ]);
                $perSection[$section] = $group->count();
            }
        });

        if ($verifiedCount === 0) {
            return response()->json([
                'success' => true,
                'verified_count' => 0,
                'message' => 'Nothing to verify — those items are no longer stored.',
            ]);
        }

        $parts = [];
        foreach ($perSection as $section => $count) {
            $parts[] = $count . ' in ' . ucfirst($section);
        }

        return response()->json([
            'success' => true,
            'verified_count' => $verifiedCount,
            'per_section' => $perSection,
            'message' => 'Verified ' . implode(' and ', $parts) . '.',
        ]);
    }

    /**
     * GET history — cursor-paged event feed, day-grouped (mirrors
     * KhaasController::getWarehouseInventoryLog's shape).
     * ?limit=30&before_id=&action=in|move|out
     */
    public function getHistory(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $limit = max(1, min(100, (int) $request->get('limit', 30)));
        $beforeId = $request->get('before_id');

        $actionFilter = $request->get('action');
        if ($actionFilter && !in_array($actionFilter, ['in', 'move', 'out', 'verify'], true)) {
            $actionFilter = null;
        }

        $query = OvernightLogModel::query();
        if ($actionFilter) {
            $query->where('action', $actionFilter);
        }
        if ($beforeId) {
            $query->where('id', '<', (int) $beforeId);
        }

        // Fetch one extra row to detect "has more" without a second COUNT query.
        $rows = $query->orderBy('id', 'desc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        $userIds = $rows->pluck('created_by')->filter()->unique()->values()->toArray();
        $userNames = empty($userIds)
            ? []
            : DB::table('t_sys_user')->whereIn('id', $userIds)->pluck('fullname', 'id')->toArray();

        $events = [];
        foreach ($rows as $log) {
            $byName = $log->created_by
                ? ($userNames[$log->created_by] ?? ('User #' . $log->created_by))
                : 'System';

            // Verify rows describe a SET, not a packet, so they carry no product name.
            // Give them a title in the same slot so every renderer works unchanged.
            $title = $log->product_name;

            switch ($log->action) {
                case 'in':
                    $label = 'Placed in ' . ucfirst((string) $log->to_section);
                    $icon = '📥';
                    $color = 'green';
                    break;
                case 'move':
                    $label = 'Moved ' . ucfirst((string) $log->from_section) . ' → ' . ucfirst((string) $log->to_section);
                    $icon = '🔄';
                    $color = 'amber';
                    break;
                case 'out':
                    $label = 'Taken out of ' . ucfirst((string) $log->from_section);
                    $icon = '📤';
                    $color = 'red';
                    break;
                // (source is appended to the detail below for both in and out)
                case 'verify':
                    $count = (int) $log->item_count;
                    $title = ucfirst((string) $log->section) . ' — ' . $count . ' item' . ($count === 1 ? '' : 's');
                    $label = 'Verified';
                    $icon = '✅';
                    $color = 'blue';
                    break;
                default:
                    $label = ucfirst((string) $log->action);
                    $icon = '📦';
                    $color = 'gray';
                    break;
            }

            $parsedDate = $log->created_at ? Carbon::parse($log->created_at) : null;

            $events[] = [
                'id' => $log->id,
                'item_id' => $log->item_id,
                'action' => $log->action,
                'label' => $label,
                'product_name' => $title,
                'quantity' => (float) $log->quantity,
                'unit' => $log->unit,
                'from_section' => $log->from_section,
                'to_section' => $log->to_section,
                'section' => $log->section,
                'item_count' => $log->item_count !== null ? (int) $log->item_count : null,
                'source' => $log->source,
                'source_label' => $log->source === 'scan' ? '🔖 scanned' : ($log->source === 'manual' ? '✏️ manual' : ''),
                // "By Taimur · 🔖 scanned" — who did it AND which way.
                'detail' => 'By ' . $byName
                    . ($log->source === 'scan' ? ' · 🔖 scanned' : ($log->source === 'manual' ? ' · ✏️ manual' : '')),
                'icon' => $icon,
                'color' => $color,
                'date' => $parsedDate ? $parsedDate->toDateTimeString() : null,
                'date_formatted' => $parsedDate ? $parsedDate->format('M d, h:i A') : 'Unknown',
                'date_ago' => $parsedDate ? $parsedDate->diffForHumans() : '',
                'date_day' => $parsedDate ? $parsedDate->toDateString() : 'unknown',
                'date_day_label' => $parsedDate
                    ? ($parsedDate->isToday() ? 'Today' : ($parsedDate->isYesterday() ? 'Yesterday' : $parsedDate->format('D, M d')))
                    : 'Unknown',
            ];
        }

        // Group by day (newest first) with per-day action counts. Closing balances
        // live in the daily summary, not here — this feed can be filtered/paged.
        $days = [];
        foreach ($events as $ev) {
            $dayKey = $ev['date_day'];
            if (!isset($days[$dayKey])) {
                $days[$dayKey] = [
                    'date' => $dayKey,
                    'label' => $ev['date_day_label'],
                    'events' => [],
                    'in_count' => 0,
                    'out_count' => 0,
                    'moved_count' => 0,
                    'verify_count' => 0,
                ];
            }
            $days[$dayKey]['events'][] = $ev;
            if ($ev['action'] === 'in') {
                $days[$dayKey]['in_count']++;
            } elseif ($ev['action'] === 'out') {
                $days[$dayKey]['out_count']++;
            } elseif ($ev['action'] === 'move') {
                $days[$dayKey]['moved_count']++;
            } elseif ($ev['action'] === 'verify') {
                $days[$dayKey]['verify_count']++;
            }
        }

        return response()->json([
            'success' => true,
            'events' => $events,
            'days' => array_values($days),
            'events_count' => count($events),
            'has_more' => $hasMore,
            'next_before_id' => count($events) > 0 ? end($events)['id'] : null,
        ]);
    }

    /**
     * GET daily-summary — per-day per-section in/out/moved + closing count/kg for a
     * month, derived by a forward walk over the append-only log (greenfield feature,
     * so balances start at zero and the log is the complete truth).
     * ?month=YYYY-MM (default: current month, app timezone)
     */
    public function getDailySummary(Request $request)
    {
        if (!$this->hasOvernightAccess()) {
            return $this->denyJson();
        }

        $month = $request->get('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Carbon::now()->format('Y-m');
        }
        $monthStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $monthEndExclusive = $monthStart->copy()->addMonth();
        $today = Carbon::today();

        // Walk to end-of-month, or today for the current month; a future month is empty.
        $walkEnd = $monthEndExclusive->copy()->subDay();
        if ($walkEnd->greaterThan($today)) {
            $walkEnd = $today->copy();
        }

        // Per-section delta identity: a log row is +1 to to_section and -1 from
        // from_section ('in' has only to, 'out' only from, 'move' both — so moves
        // need no special-casing). ⚠ COALESCE is load-bearing: (NULL='chiller') is
        // NULL in MySQL, and a bare SUM would silently drop every 'in' row.
        $deltaSelect = "
            COALESCE(SUM(COALESCE(to_section = 'chiller', 0) - COALESCE(from_section = 'chiller', 0)), 0) AS chiller_count,
            COALESCE(SUM(CASE WHEN unit = 'kg' AND to_section = 'chiller' THEN quantity
                              WHEN unit = 'kg' AND from_section = 'chiller' THEN -quantity ELSE 0 END), 0) AS chiller_kg,
            COALESCE(SUM(COALESCE(to_section = 'freezer', 0) - COALESCE(from_section = 'freezer', 0)), 0) AS freezer_count,
            COALESCE(SUM(CASE WHEN unit = 'kg' AND to_section = 'freezer' THEN quantity
                              WHEN unit = 'kg' AND from_section = 'freezer' THEN -quantity ELSE 0 END), 0) AS freezer_kg";

        // Verify rows carry NULL from/to precisely so they contribute 0 above; the
        // action filter is the second line of defence against a future edit that
        // populates those columns and silently debits a verified section.
        $opening = DB::table('t_crm_overnight_log')
            ->whereIn('action', self::MOVEMENT_ACTIONS)
            ->where('created_at', '<', $monthStart->toDateTimeString())
            ->selectRaw($deltaSelect)
            ->first();

        $monthRows = DB::table('t_crm_overnight_log')
            ->whereIn('action', self::MOVEMENT_ACTIONS)
            ->where('created_at', '>=', $monthStart->toDateTimeString())
            ->where('created_at', '<', $monthEndExclusive->toDateTimeString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['created_at', 'action', 'from_section', 'to_section', 'quantity', 'unit']);

        // Group by calendar day in PHP (app timezone) — matches the khaas convention
        // and sidesteps MySQL-vs-PHP timezone drift.
        $byDay = [];
        foreach ($monthRows as $r) {
            $byDay[Carbon::parse($r->created_at)->toDateString()][] = $r;
        }

        // Who verified what, per day — the audit answer to "was this checked, and by whom?"
        $verifRows = DB::table('t_crm_overnight_log as l')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
            ->where('l.action', 'verify')
            ->where('l.created_at', '>=', $monthStart->toDateTimeString())
            ->where('l.created_at', '<', $monthEndExclusive->toDateTimeString())
            ->orderBy('l.created_at')
            ->get(['l.created_at', 'l.section', 'l.item_count', 'l.quantity', 'u.fullname']);
        $verifByDay = [];
        foreach ($verifRows as $v) {
            $at = Carbon::parse($v->created_at);
            $verifByDay[$at->toDateString()][] = [
                'section' => $v->section,
                'by_name' => $v->fullname ?: 'System',
                'at_formatted' => $at->format('h:i A'),
                'item_count' => (int) $v->item_count,
                'total_kg' => round((float) $v->quantity, 3),
            ];
        }

        $running = [
            'chiller' => ['count' => (int) $opening->chiller_count, 'kg' => (float) $opening->chiller_kg],
            'freezer' => ['count' => (int) $opening->freezer_count, 'kg' => (float) $opening->freezer_kg],
        ];

        $emptyStats = [
            'in_count' => 0, 'in_kg' => 0.0,
            'out_count' => 0, 'out_kg' => 0.0,
            'moved_in_count' => 0, 'moved_out_count' => 0,
        ];

        $days = [];
        $cursor = $monthStart->copy();
        while ($cursor->lessThanOrEqualTo($walkEnd)) {
            $dayKey = $cursor->toDateString();
            $stats = ['chiller' => $emptyStats, 'freezer' => $emptyStats];

            foreach ($byDay[$dayKey] ?? [] as $r) {
                $kg = $r->unit === 'kg' ? (float) $r->quantity : 0.0;
                if ($r->to_section) {
                    $sec = $r->to_section;
                    if ($r->action === 'in') {
                        $stats[$sec]['in_count']++;
                        $stats[$sec]['in_kg'] += $kg;
                    } elseif ($r->action === 'move') {
                        $stats[$sec]['moved_in_count']++;
                    }
                    $running[$sec]['count']++;
                    $running[$sec]['kg'] += $kg;
                }
                if ($r->from_section) {
                    $sec = $r->from_section;
                    if ($r->action === 'out') {
                        $stats[$sec]['out_count']++;
                        $stats[$sec]['out_kg'] += $kg;
                    } elseif ($r->action === 'move') {
                        $stats[$sec]['moved_out_count']++;
                    }
                    $running[$sec]['count']--;
                    $running[$sec]['kg'] -= $kg;
                }
            }

            $dayVerifications = $verifByDay[$dayKey] ?? [];
            $dayEntry = [
                'date' => $dayKey,
                'label' => $cursor->isToday()
                    ? 'Today'
                    : ($cursor->isYesterday() ? 'Yesterday' : $cursor->format('D, M d')),
                'has_activity' => isset($byDay[$dayKey]) || !empty($dayVerifications),
                'verifications' => $dayVerifications,
                'verified_by_names' => implode(', ', array_values(array_unique(
                    array_column($dayVerifications, 'by_name')
                ))),
            ];
            foreach (self::SECTIONS as $sec) {
                $dayEntry[$sec] = [
                    'in_count' => $stats[$sec]['in_count'],
                    'in_kg' => round($stats[$sec]['in_kg'], 3),
                    'out_count' => $stats[$sec]['out_count'],
                    'out_kg' => round($stats[$sec]['out_kg'], 3),
                    'moved_in_count' => $stats[$sec]['moved_in_count'],
                    'moved_out_count' => $stats[$sec]['moved_out_count'],
                    'closing_count' => $running[$sec]['count'],
                    'closing_kg' => round($running[$sec]['kg'], 3),
                ];
            }
            $days[] = $dayEntry;
            $cursor->addDay();
        }
        $days = array_reverse($days); // newest first

        // ❄️ Per-day CATEGORY breakdown (Aug-2026). The day rows above answer
        // "how much moved"; this answers "of what" — the question a manager
        // comparing storage against sales actually asks. Read from the same log
        // in ONE grouped query (never per-day queries), joined out to the
        // product's Level-1 category, which is the same vocabulary the sales
        // reports use. Kept as a separate map so the day totals above are
        // untouched: a product deleted since, or a packet with no category,
        // still counts in the day total and simply lands under "Uncategorized".
        $catRows = DB::table('t_crm_overnight_log as l')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.action', ['in', 'out'])
            ->where('l.created_at', '>=', $monthStart->toDateTimeString())
            ->where('l.created_at', '<', $monthEndExclusive->toDateTimeString())
            ->selectRaw("
                DATE(l.created_at) AS d,
                COALESCE(NULLIF(TRIM(p.attribute_1), ''), 'Uncategorized') AS category,
                l.action AS action,
                COALESCE(l.from_section, l.to_section) AS sec_key,
                COUNT(*) AS packets,
                COALESCE(SUM(CASE WHEN l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS kg
            ")
            // GROUP BY the SELECT ALIASES — repeating a COALESCE expression here
            // trips MariaDB ONLY_FULL_GROUP_BY (error 1055).
            // ⚠ The section alias is deliberately NOT called `section`: this table
            // HAS a real `section` column, so GROUP BY would bind to the column
            // instead of this expression and throw 1055 anyway.
            ->groupBy('d', 'category', 'action', 'sec_key')
            ->get();

        $byDayCategory = [];
        foreach ($catRows as $r) {
            $sec = $r->sec_key ?: 'chiller';
            $slot = &$byDayCategory[$r->d][$sec][$r->category];
            if (!is_array($slot)) {
                $slot = ['in_kg' => 0.0, 'in_count' => 0, 'out_kg' => 0.0, 'out_count' => 0];
            }
            $slot[$r->action . '_kg']    += (float) $r->kg;
            $slot[$r->action . '_count'] += (int) $r->packets;
            unset($slot);
        }
        foreach ($days as &$d) {
            $d['categories'] = $byDayCategory[$d['date']] ?? [];
        }
        unset($d);

        // Live truth from the item table (what the Current tab shows).
        $live = OvernightItemModel::where('status', 'stored')
            ->selectRaw("
                COALESCE(SUM(section = 'chiller'), 0) AS chiller_count,
                COALESCE(SUM(CASE WHEN section = 'chiller' AND unit = 'kg' THEN quantity ELSE 0 END), 0) AS chiller_kg,
                COALESCE(SUM(section = 'freezer'), 0) AS freezer_count,
                COALESCE(SUM(CASE WHEN section = 'freezer' AND unit = 'kg' THEN quantity ELSE 0 END), 0) AS freezer_kg")
            ->first();

        // Reconciliation: the log replayed from the beginning must equal the live
        // item table. Only diverges on hand-edited SQL — surface it, don't hide it.
        $logNow = DB::table('t_crm_overnight_log')
            ->whereIn('action', self::MOVEMENT_ACTIONS)
            ->selectRaw($deltaSelect)
            ->first();
        $reconciled = (int) $logNow->chiller_count === (int) $live->chiller_count
            && (int) $logNow->freezer_count === (int) $live->freezer_count
            && abs((float) $logNow->chiller_kg - (float) $live->chiller_kg) < 0.005
            && abs((float) $logNow->freezer_kg - (float) $live->freezer_kg) < 0.005;

        return response()->json([
            'success' => true,
            'month' => $month,
            'days' => $days,
            'current' => [
                'chiller' => ['count' => (int) $live->chiller_count, 'kg' => round((float) $live->chiller_kg, 3)],
                'freezer' => ['count' => (int) $live->freezer_count, 'kg' => round((float) $live->freezer_kg, 3)],
            ],
            'reconciled' => $reconciled,
        ]);
    }
}
