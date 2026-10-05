<?php

namespace App\Services\Khaas;

use App\Models\FIN\LedgerModel;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\IngredientOpeningModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ❄🧂 Frozen ingredient stock — start + bought − used = left (owner, Sep-30-2026).
 *
 * ⭐⭐ ONE ENGINE. The stock sheet, the recipe editor, the by-weight purchase sheet and
 * Month Review all read "on the shelf" from HERE (FrozenCostingService delegates), so no
 * two screens can ever show two different numbers for the same salt.
 *
 * The rules, all of them already true before this class and kept exactly:
 *   • The figure starts from the latest START or ADJUST row (t_crm_khaas_ingredient_opening,
 *     kind opening|count) on or before the day asked about. No row = "not tracked" (null),
 *     never a number that looks exact and is not.
 *   • Bought = tagged, itemised vendor-bill lines (qty_base) — which only a BY-WEIGHT
 *     purchase or a scanned bill has. A by-total bill has no quantities and never counts.
 *   • Used = t_crm_khaas_batch_consumption (recipe × packs made, written at End Batch).
 *   • ⚠⚠ STRICTLY AFTER the anchor's date: a weigh-in already reflects everything that
 *     happened on its own day. A bill dated the same day or earlier is inside the figure.
 *
 * NEW (Sep-30):
 *   • kind `check` — a weigh-in that is RECORDED and shows its gap but does NOT move the
 *     figure. It is what someone without `adjust_khaas_stock` saves, so once the owner
 *     locks adjustments the team can still say what they see, and cannot wipe a shortage.
 *   • The gap of every weigh-in is worked out at read time: what the system expected on
 *     that day vs what was typed. A late bill dated before the weigh-in shrinks the gap on
 *     the next read, which is the truth.
 *   • A count never creates usage and never touches cost per pack.
 */
class IngredientStockService
{
    public const ANCHOR_KINDS = [IngredientOpeningModel::KIND_OPENING, IngredientOpeningModel::KIND_COUNT];

    /** Same posted set the costing reads. */
    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** A typed figure this far from the system's gets an "are you sure?" on both screens. */
    public const SURE_FACTOR = 5.0;

    // =================================================================
    //  ON THE SHELF
    // =================================================================

    /**
     * What should be on the shelf at the end of $asOf, per ingredient.
     *
     * @param  int[] $ingredientIds
     * @return array<int,float|null> null = not tracked (no start on or before $asOf)
     */
    public function onShelf(int $businessUnitId, array $ingredientIds, $asOf = null): array
    {
        $out = [];
        $ingredientIds = array_values(array_unique(array_map('intval', $ingredientIds)));
        foreach ($ingredientIds as $id) {
            $out[$id] = null;
        }
        if (!$ingredientIds) {
            return $out;
        }

        $asOfDate = $this->day($asOf);

        try {
            $anchor = $this->latestAnchors($ingredientIds, $asOfDate);
            if (!$anchor) {
                return $out;
            }

            $earliest = min(array_column($anchor, 'date'));
            $bought   = $this->boughtByDay($businessUnitId, array_keys($anchor), $earliest, $asOfDate);
            $used     = $this->usedByDay($businessUnitId, array_keys($anchor), $earliest, $asOfDate);

            foreach ($anchor as $id => $a) {
                $out[$id] = $a['qty']
                    + $this->sumAfter($bought[$id] ?? [], $a['date'], $asOfDate)
                    - $this->sumAfter($used[$id] ?? [], $a['date'], $asOfDate);
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen stock: on-shelf failed', ['error' => $e->getMessage()]);
            foreach ($ingredientIds as $id) {
                $out[$id] = null;
            }
        }

        return $out;
    }

    /**
     * The same figure, shaped for a screen: ingredient id => {qty, text, started_on} or null.
     * Meat is left out on purpose — Storage counts it for real.
     *
     * @return array<int,array|null>
     */
    public function onShelfShaped(int $businessUnitId, array $ingredientIds, $asOf = null): array
    {
        $ingredientIds = array_values(array_unique(array_map('intval', $ingredientIds)));
        if (!$ingredientIds) {
            return [];
        }
        try {
            $ings = IngredientModel::whereIn('id', $ingredientIds)->get()->keyBy('id');
            $raw  = $this->onShelf($businessUnitId, $ingredientIds, $asOf);
            $out  = [];
            foreach ($ingredientIds as $id) {
                $ing = $ings->get($id);
                if (!$ing || $ing->isMeat() || $raw[$id] === null) {
                    $out[$id] = null;
                    continue;
                }
                $out[$id] = ['qty' => round($raw[$id], 3), 'text' => $ing->phrase($raw[$id])];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('Frozen stock: shaped on-shelf failed', ['error' => $e->getMessage()]);
            return array_fill_keys($ingredientIds, null);
        }
    }

    // =================================================================
    //  THE SHEET
    // =================================================================

    /**
     * Every non-meat Frozen ingredient with its figure, its last weigh-in, and whether a
     * by-weight product can ever count its purchases. Hidden ones come separately so the
     * screen can fold them away and offer "Unhide".
     *
     * @param array<int,array>|null $prices ingredient id => IngredientPriceService::latest() row (null = no rupees)
     */
    public function sheet(int $businessUnitId, ?array $prices = null): array
    {
        $all = IngredientModel::where('business_unit_id', $businessUnitId)
            ->whereNull('storage_product_id')
            ->orderByRaw("FIELD(kind,'vegetable','dairy','dry','packaging','other')")
            ->orderBy('name')
            ->get();

        $ids     = $all->pluck('id')->map(fn ($i) => (int) $i)->all();
        $today   = now()->toDateString();
        $shelf   = $this->onShelf($businessUnitId, $ids, $today);
        $entries = $this->entriesFor($ids);
        $gaps    = $this->gapsFor($businessUnitId, $entries);
        $names   = $this->userNames(array_merge(...array_map(fn ($rows) => array_column($rows, 'created_by'), $entries ?: [[]])));

        $byWeight = $this->byWeightLinked($businessUnitId, $ids);
        $current  = $this->currentRecipeNames($ids);

        $rows = [];
        foreach ($all as $ing) {
            $id    = (int) $ing->id;
            $list  = $entries[$id] ?? [];
            $last  = $list ? end($list) : null;
            $start = null;
            foreach ($list as $e) {
                if (in_array($e['kind'], self::ANCHOR_KINDS, true)) {
                    $start = $e['date'];
                    break;
                }
            }
            $price = $prices[$id] ?? null;

            $rows[] = $ing->shape() + [
                'on_shelf'        => $shelf[$id] === null ? null : round($shelf[$id], 3),
                'on_shelf_text'   => $shelf[$id] === null ? null : $ing->phrase($shelf[$id]),
                'tracked'         => $shelf[$id] !== null,
                'started_on'      => $start,
                'last_entry'      => $last ? $this->shapeEntry($ing, $last, $gaps[$last['id']] ?? null, $names, $price) : null,
                'by_weight'       => isset($byWeight[$id]),
                'current_recipes' => $current[$id] ?? [],
                'price_per_base'  => $price ? (float) $price['price_per_base'] : null,
            ];
        }

        return [
            'rows'   => array_values(array_filter($rows, fn ($r) => $r['is_active'])),
            'hidden' => array_values(array_filter($rows, fn ($r) => !$r['is_active'])),
            'today'  => $today,
            'yesterday' => now()->subDay()->toDateString(),
            'sure_factor' => self::SURE_FACTOR,
        ];
    }

    /** Newest first: every weigh-in for one ingredient, with the system figure it was compared to. */
    public function history(int $businessUnitId, IngredientModel $ing, ?array $price = null, int $limit = 40): array
    {
        $entries = $this->entriesFor([(int) $ing->id]);
        $gaps    = $this->gapsFor($businessUnitId, $entries);
        $list    = $entries[(int) $ing->id] ?? [];
        $names   = $this->userNames(array_column($list, 'created_by'));

        $out = [];
        foreach (array_reverse($list) as $e) {
            $out[] = $this->shapeEntry($ing, $e, $gaps[$e['id']] ?? null, $names, $price);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    // =================================================================
    //  SAVING
    // =================================================================

    /**
     * Save one sheet of weigh-ins — ALL OR NOTHING. Each entry: {ingredient_id, qty, unit?, note?}.
     * A blank qty is skipped (= "not tracked / not weighed"), never saved as zero.
     *
     * The KIND is decided here, never by the client:
     *   can adjust + nothing before → opening (the start)
     *   can adjust + a start exists → count  (sets the figure; the gap is kept and shown)
     *   cannot adjust + a start     → check  (recorded, gap shown, figure unchanged)
     *   cannot adjust + no start    → refused (a start needs "Adjust Frozen stock")
     *
     * @return array{ok:bool, errors:array<int,string>, saved:array, message:string}
     */
    public function save(int $businessUnitId, array $entries, ?string $date, int $userId, bool $canAdjust): array
    {
        $today     = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $date      = $date ?: $today;
        if (!in_array($date, [$today, $yesterday], true)) {
            return $this->refusal('A weigh-in can only be dated today or yesterday.');
        }

        $clean  = [];
        $errors = [];
        foreach ($entries as $row) {
            $id = (int) ($row['ingredient_id'] ?? 0);
            $rawQty = $row['qty'] ?? null;
            if (!$id || $rawQty === null || trim((string) $rawQty) === '') {
                continue;
            }
            if (isset($clean[$id])) {
                $errors[$id] = 'This ingredient is on the sheet twice.';
                continue;
            }

            $ing = IngredientModel::find($id);
            if (!$ing || (int) $ing->business_unit_id !== $businessUnitId) {
                $errors[$id] = 'That ingredient is not on the Frozen list.';
                continue;
            }
            if ($ing->isMeat()) {
                $errors[$id] = "{$ing->name} is meat — Storage counts it.";
                continue;
            }
            if (!$ing->is_active) {
                $errors[$id] = "{$ing->name} is hidden. Unhide it first.";
                continue;
            }

            $qtyText = str_replace(',', '', trim((string) $rawQty));
            if (!is_numeric($qtyText) || (float) $qtyText < 0) {
                $errors[$id] = "{$ing->name}: give a number that is zero or more.";
                continue;
            }
            $unit    = (string) ($row['unit'] ?? $ing->base_unit);
            $allowed = IngredientModel::DISPLAY_UNITS[$ing->base_unit] ?? [$ing->base_unit];
            if (!in_array($unit, $allowed, true)) {
                $errors[$id] = "{$ing->name} is counted in " . implode(' or ', $allowed) . ", not {$unit}.";
                continue;
            }
            $base = IngredientModel::toBase((float) $qtyText, $unit);
            if ($ing->base_unit === IngredientModel::UNIT_PCS && abs($base - round($base)) > 0.0005) {
                $errors[$id] = "{$ing->name} is counted in whole pieces.";
                continue;
            }

            $later = DB::table('t_crm_khaas_ingredient_opening')
                ->where('ingredient_id', $id)
                ->whereIn('kind', self::ANCHOR_KINDS)
                ->where('counted_on', '>', $date)
                ->orderByDesc('counted_on')
                ->value('counted_on');
            if ($later) {
                $errors[$id] = "{$ing->name} already has a figure on " . Carbon::parse($later)->format('j M') . ', after this date.';
                continue;
            }

            $hasStart = DB::table('t_crm_khaas_ingredient_opening')
                ->where('ingredient_id', $id)
                ->whereIn('kind', self::ANCHOR_KINDS)
                ->where('counted_on', '<=', $date)
                ->exists();

            if ($canAdjust) {
                $kind = $hasStart ? IngredientOpeningModel::KIND_COUNT : IngredientOpeningModel::KIND_OPENING;
            } elseif ($hasStart) {
                $kind = IngredientOpeningModel::KIND_CHECK;
            } else {
                $errors[$id] = "{$ing->name} has no starting stock yet — Taimur or Shabib can enter the start.";
                continue;
            }

            $note = trim((string) ($row['note'] ?? ''));
            $clean[$id] = [
                'ingredient_id' => $id,
                'kind'          => $kind,
                'counted_on'    => $date,
                'qty_base'      => round($base, 3),
                'rupees'        => null,
                'note'          => $note !== '' ? mb_substr($note, 0, 255) : null,
                'created_by'    => $userId ?: null,
                'created_at'    => now(),
            ];
        }

        if ($errors) {
            return [
                'ok'      => false,
                'errors'  => $errors,
                'saved'   => [],
                'message' => 'Nothing was saved — ' . count($errors) . ' line' . (count($errors) === 1 ? ' needs' : 's need') . ' fixing.',
            ];
        }
        if (!$clean) {
            return $this->refusal('Type at least one amount.');
        }

        $ids = [];
        DB::transaction(function () use ($clean, &$ids) {
            foreach ($clean as $iid => $row) {
                $ids[$iid] = (int) DB::table('t_crm_khaas_ingredient_opening')->insertGetId($row);
            }
        });

        // Say what each one did, with its gap, from the same read path every screen uses.
        $entries = $this->entriesFor(array_keys($clean));
        $gaps    = $this->gapsFor($businessUnitId, $entries);
        $saved   = [];
        $counts  = ['opening' => 0, 'count' => 0, 'check' => 0];
        foreach ($clean as $iid => $row) {
            $counts[$row['kind']]++;
            $gap = $gaps[$ids[$iid]] ?? null;
            $ing = IngredientModel::find($iid);
            $saved[] = [
                'ingredient_id' => $iid,
                'kind'          => $row['kind'],
                'qty_text'      => $ing->phrase($row['qty_base']),
                'expected_text' => $gap && $gap['expected'] !== null ? $ing->phrase($gap['expected']) : null,
                'gap'           => $gap['gap'] ?? null,
                'gap_text'      => $gap && $gap['gap'] !== null ? $this->gapText($ing, $gap['gap']) : null,
            ];
        }

        $parts = [];
        if ($counts['opening']) {
            $parts[] = "{$counts['opening']} start" . ($counts['opening'] === 1 ? '' : 's');
        }
        if ($counts['count']) {
            $parts[] = "{$counts['count']} adjusted";
        }
        if ($counts['check']) {
            $parts[] = "{$counts['check']} weigh-in" . ($counts['check'] === 1 ? '' : 's') . ' recorded (the figure is unchanged)';
        }

        return ['ok' => true, 'errors' => [], 'saved' => $saved, 'message' => 'Saved: ' . implode(', ', $parts) . '.'];
    }

    // =================================================================
    //  MONTH REVIEW
    // =================================================================

    /**
     * Stock columns for Month Review: start, adjusted, left, latest check.
     * For a FULL month (tracking started before it): start + bought − used + adjusted = left.
     * The month tracking starts in is `partial` and says so.
     *
     * @return array<int,array> ingredient id => columns
     */
    public function monthColumns(int $businessUnitId, array $ingredientIds, Carbon $start, Carbon $end): array
    {
        $out = [];
        $ingredientIds = array_values(array_unique(array_map('intval', $ingredientIds)));
        if (!$ingredientIds) {
            return $out;
        }

        try {
            $startDay = $start->toDateString();
            $endDay   = $end->toDateString();
            $before   = $this->onShelf($businessUnitId, $ingredientIds, $start->copy()->subDay()->toDateString());
            $left     = $this->onShelf($businessUnitId, $ingredientIds, $endDay);
            $entries  = $this->entriesFor($ingredientIds);
            $gaps     = $this->gapsFor($businessUnitId, $entries);

            foreach ($ingredientIds as $id) {
                $list = $entries[$id] ?? [];
                $adjusted  = 0.0;
                $startedOn = null;
                $startedQty = null;
                $lastCheck = null;
                $effective = $this->effectiveAnchors($list);

                foreach ($list as $e) {
                    if ($e['date'] < $startDay || $e['date'] > $endDay) {
                        continue;
                    }
                    if ($e['kind'] === IngredientOpeningModel::KIND_CHECK) {
                        $lastCheck = $e;
                        continue;
                    }
                    if (!isset($effective[$e['id']])) {
                        continue;   // replaced later the same day
                    }
                    $g = $gaps[$e['id']] ?? null;
                    if ($g && $g['expected'] === null) {
                        // the start itself: it is not an adjustment
                        if ($before[$id] === null && $startedOn === null) {
                            $startedOn  = $e['date'];
                            $startedQty = $e['qty'];
                        }
                        continue;
                    }
                    $adjusted += (float) ($g['gap'] ?? 0);
                }

                $out[$id] = [
                    'start_qty'   => $before[$id] === null ? null : round($before[$id], 3),
                    'started_on'  => $startedOn,
                    'started_qty' => $startedQty,
                    'partial'     => $before[$id] === null && $startedOn !== null,
                    'adjusted'    => round($adjusted, 3),
                    'left_qty'    => $left[$id] === null ? null : round($left[$id], 3),
                    'last_check'  => $lastCheck ? [
                        'entry' => $lastCheck,
                        'gap'   => $gaps[$lastCheck['id']] ?? null,
                    ] : null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Frozen stock: month columns failed', ['error' => $e->getMessage()]);
            return [];
        }

        return $out;
    }

    // =================================================================
    //  PIECES
    // =================================================================

    /** A short "+80 g" / "80 g short" in the ingredient's own words. */
    public function gapText(IngredientModel $ing, float $gap): string
    {
        if (abs($gap) < 0.0005) {
            return 'matches';
        }
        return $gap < 0
            ? $ing->phrase(abs($gap)) . ' short'
            : $ing->phrase($gap) . ' more than expected';
    }

    /** @return array<int,array> ingredient id => entries oldest first */
    private function entriesFor(array $ingredientIds): array
    {
        $out = [];
        if (!$ingredientIds) {
            return $out;
        }
        try {
            $rows = DB::table('t_crm_khaas_ingredient_opening')
                ->whereIn('ingredient_id', $ingredientIds)
                ->orderBy('counted_on')->orderBy('id')
                ->get(['id', 'ingredient_id', 'kind', 'counted_on', 'qty_base', 'note', 'created_by', 'created_at']);
        } catch (\Throwable $e) {
            return $out;
        }
        foreach ($rows as $r) {
            $out[(int) $r->ingredient_id][] = [
                'id'         => (int) $r->id,
                'kind'       => (string) $r->kind,
                'date'       => Carbon::parse($r->counted_on)->toDateString(),
                'qty'        => (float) $r->qty_base,
                'note'       => $r->note,
                'created_by' => $r->created_by ? (int) $r->created_by : null,
                'created_at' => $r->created_at,
            ];
        }
        return $out;
    }

    /**
     * The last start/adjust of each DAY is the one that holds; an earlier one that day was
     * replaced. @return array<int,true> entry id => true
     */
    private function effectiveAnchors(array $list): array
    {
        $lastOfDay = [];
        foreach ($list as $e) {
            if (in_array($e['kind'], self::ANCHOR_KINDS, true)) {
                $lastOfDay[$e['date']] = $e['id'];
            }
        }
        return array_fill_keys(array_values($lastOfDay), true);
    }

    /**
     * For every entry: the system figure it was compared with, and the gap.
     *   start/adjust → the last effective start/adjust of an EARLIER day + movement up to its day
     *   check        → the last effective start/adjust of an earlier day, or of the SAME day
     *                  if it was saved before the check, + movement up to its day
     * No earlier figure → expected null (a start has no gap).
     *
     * @return array<int,array{expected:?float,gap:?float,replaced:bool}> entry id => facts
     */
    private function gapsFor(int $businessUnitId, array $entriesByIngredient): array
    {
        $out = [];
        if (!$entriesByIngredient) {
            return $out;
        }

        $first = null;
        $last  = null;
        foreach ($entriesByIngredient as $list) {
            foreach ($list as $e) {
                $first = $first === null || $e['date'] < $first ? $e['date'] : $first;
                $last  = $last === null || $e['date'] > $last ? $e['date'] : $last;
            }
        }
        if ($first === null) {
            return $out;
        }

        $ids    = array_keys($entriesByIngredient);
        $bought = $this->boughtByDay($businessUnitId, $ids, $first, $last);
        $used   = $this->usedByDay($businessUnitId, $ids, $first, $last);

        foreach ($entriesByIngredient as $iid => $list) {
            $effective = $this->effectiveAnchors($list);
            foreach ($list as $e) {
                $base = null;
                foreach ($list as $p) {
                    if ($p['id'] === $e['id'] || !isset($effective[$p['id']])) {
                        continue;
                    }
                    $earlierDay = $p['date'] < $e['date'];
                    $sameDayBeforeCheck = $e['kind'] === IngredientOpeningModel::KIND_CHECK
                        && $p['date'] === $e['date'] && $p['id'] < $e['id'];
                    if ($earlierDay || $sameDayBeforeCheck) {
                        $base = $p;   // list is oldest first, so the last hit is the latest
                    }
                }

                $replaced = in_array($e['kind'], self::ANCHOR_KINDS, true) && !isset($effective[$e['id']]);
                if (!$replaced && $e['kind'] === IngredientOpeningModel::KIND_CHECK) {
                    foreach ($list as $p) {
                        if ($p['kind'] === IngredientOpeningModel::KIND_CHECK && $p['date'] === $e['date'] && $p['id'] > $e['id']) {
                            $replaced = true;
                            break;
                        }
                    }
                }

                if (!$base) {
                    $out[$e['id']] = ['expected' => null, 'gap' => null, 'replaced' => $replaced];
                    continue;
                }

                $expected = $base['qty']
                    + $this->sumAfter($bought[$iid] ?? [], $base['date'], $e['date'])
                    - $this->sumAfter($used[$iid] ?? [], $base['date'], $e['date']);

                $out[$e['id']] = [
                    'expected' => round($expected, 3),
                    'gap'      => round($e['qty'] - $expected, 3),
                    'replaced' => $replaced,
                ];
            }
        }

        return $out;
    }

    private function shapeEntry(IngredientModel $ing, array $e, ?array $gap, array $names, ?array $price): array
    {
        $gapQty = $gap['gap'] ?? null;
        $rs = ($price && $gapQty !== null) ? round($gapQty * (float) $price['price_per_base'], 0) : null;

        return [
            'id'            => $e['id'],
            'kind'          => $e['kind'],
            'kind_label'    => [
                IngredientOpeningModel::KIND_OPENING => 'Start',
                IngredientOpeningModel::KIND_COUNT   => 'Adjusted',
                IngredientOpeningModel::KIND_CHECK   => 'Weighed',
            ][$e['kind']] ?? $e['kind'],
            'date'          => $e['date'],
            'date_text'     => Carbon::parse($e['date'])->format('j M'),
            'qty'           => round($e['qty'], 3),
            'qty_text'      => $ing->phrase($e['qty']),
            'expected'      => $gap['expected'] ?? null,
            'expected_text' => isset($gap['expected']) && $gap['expected'] !== null ? $ing->phrase($gap['expected']) : null,
            'gap'           => $gapQty,
            'gap_text'      => $gapQty !== null ? $this->gapText($ing, $gapQty) : null,
            'gap_rupees'    => $rs,
            'replaced'      => (bool) ($gap['replaced'] ?? false),
            'note'          => $e['note'],
            'by'            => $e['created_by'] ? ($names[$e['created_by']] ?? 'Someone') : null,
        ];
    }

    /** ingredient id => [date => qty], tagged lines of posted vendor purchases in [from, to]. */
    private function boughtByDay(int $businessUnitId, array $ids, string $from, string $to): array
    {
        $out = [];
        if (!$ids) {
            return $out;
        }
        $rows = DB::table('t_fin_vendor_purchase_items as i')
            ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
            ->where('l.business_unit_id', $businessUnitId)
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereIn('i.ingredient_id', $ids)
            ->whereNotNull('i.qty_base')
            ->whereBetween('l.transaction_date', [$from, $to])
            ->select('i.ingredient_id', 'l.transaction_date', DB::raw('SUM(i.qty_base) as qty'))
            ->groupBy('i.ingredient_id', 'l.transaction_date')
            ->get();
        foreach ($rows as $r) {
            $d = Carbon::parse($r->transaction_date)->toDateString();
            $out[(int) $r->ingredient_id][$d] = ($out[(int) $r->ingredient_id][$d] ?? 0) + (float) $r->qty;
        }
        return $out;
    }

    /** ingredient id => [date => qty], recipe consumption in [from, to]. */
    private function usedByDay(int $businessUnitId, array $ids, string $from, string $to): array
    {
        $out = [];
        if (!$ids) {
            return $out;
        }
        $rows = DB::table('t_crm_khaas_batch_consumption')
            ->where('business_unit_id', $businessUnitId)
            ->whereIn('ingredient_id', $ids)
            ->whereBetween('made_on', [$from, $to])
            ->select('ingredient_id', 'made_on', DB::raw('SUM(qty_base) as qty'))
            ->groupBy('ingredient_id', 'made_on')
            ->get();
        foreach ($rows as $r) {
            $d = Carbon::parse($r->made_on)->toDateString();
            $out[(int) $r->ingredient_id][$d] = ($out[(int) $r->ingredient_id][$d] ?? 0) + (float) $r->qty;
        }
        return $out;
    }

    /** Sum of day => qty strictly after $after and up to (including) $upTo. */
    private function sumAfter(array $byDay, string $after, string $upTo): float
    {
        $sum = 0.0;
        foreach ($byDay as $d => $q) {
            if ($d > $after && $d <= $upTo) {
                $sum += $q;
            }
        }
        return $sum;
    }

    /**
     * The latest EFFECTIVE start/adjust on or before $asOf, per ingredient (a later row the
     * same day replaces an earlier one). @return array<int,array{date:string,qty:float}>
     */
    private function latestAnchors(array $ids, string $asOf): array
    {
        $rows = DB::table('t_crm_khaas_ingredient_opening')
            ->whereIn('ingredient_id', $ids)
            ->whereIn('kind', self::ANCHOR_KINDS)
            ->where('counted_on', '<=', $asOf)
            ->orderBy('ingredient_id')
            ->orderByDesc('counted_on')
            ->orderByDesc('id')
            ->get(['ingredient_id', 'counted_on', 'qty_base']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->ingredient_id] ??= [
                'date' => Carbon::parse($r->counted_on)->toDateString(),
                'qty'  => (float) $r->qty_base,
            ];
        }
        return $out;
    }

    /** Ingredients a by-weight vendor's ACTIVE product is tagged to. @return array<int,true> */
    private function byWeightLinked(int $businessUnitId, array $ids): array
    {
        try {
            return array_fill_keys(DB::table('t_fin_vendor_products as vp')
                ->join('t_fin_vendors as v', 'v.id', '=', 'vp.vendor_id')
                ->whereIn('vp.ingredient_id', $ids)
                ->where('vp.is_active', 1)
                ->where('v.default_purchase_method', 'by_weight')
                ->distinct()->pluck('vp.ingredient_id')->map(fn ($i) => (int) $i)->all(), true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** ingredient id => product titles whose CURRENT recipe uses it. */
    public function currentRecipeNames(array $ids): array
    {
        $out = [];
        if (!$ids) {
            return $out;
        }
        try {
            $rows = DB::table('t_crm_khaas_recipe_line as l')
                ->join('t_crm_khaas_recipe as r', 'r.id', '=', 'l.recipe_id')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'r.product_id')
                ->whereIn('l.ingredient_id', $ids)
                ->where('r.is_current', 1)
                ->orderBy('p.title')
                ->get(['l.ingredient_id', 'p.title']);
            foreach ($rows as $r) {
                $out[(int) $r->ingredient_id][] = $r->title ?: 'a product';
            }
        } catch (\Throwable $e) {
        }
        return array_map(fn ($a) => array_values(array_unique($a)), $out);
    }

    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        try {
            return DB::table('t_sys_user')->whereIn('id', $ids)->pluck('fullname', 'id')
                ->map(fn ($n) => trim(explode(' ', (string) $n)[0]) ?: 'Someone')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function day($asOf): string
    {
        if ($asOf instanceof Carbon) {
            return $asOf->toDateString();
        }
        return $asOf ? Carbon::parse($asOf)->toDateString() : now()->toDateString();
    }

    private function refusal(string $message): array
    {
        return ['ok' => false, 'errors' => [], 'saved' => [], 'message' => $message];
    }
}
