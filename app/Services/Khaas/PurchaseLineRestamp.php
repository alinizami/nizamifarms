<?php

namespace App\Services\Khaas;

use App\Models\FIN\LedgerModel;
use App\Models\FIN\VendorProductModel;
use App\Models\Khaas\IngredientModel;
use App\Services\AuditLogger;
use App\Services\FIN\VendorUnits;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🧾↩ Count a product's EARLIER bills towards the ingredient it was tagged with TODAY.
 *
 * A bill line is stamped with its ingredient when the bill is recorded, from the
 * catalogue tag AS IT STOOD THAT DAY (VendorPurchaseItemModel::ingredientColumnsFor).
 * Tag the product afterwards and nothing looks back — so Qasim links Puck Cream
 * Cheese to Cheese on the 5th, and the bill of the 3rd never prices Cheese.
 *
 * ⚠⚠ Never automatic. "Not an ingredient" is not stored on a line (it resolves to the
 *    same nulls as "untagged at the time"), so only the person can say whether an
 *    old line should count. This class PREVIEWS what would move, in plain numbers, and
 *    APPLIES only what was explicitly accepted. It touches ingredient_id / qty_base
 *    on the product's own lines and nothing else — no ledger row, no amount.
 *
 * Two kinds of line can be offered:
 *   • `uncounted` — lines of this product with NO ingredient (the common case);
 *   • `moved`     — lines stamped with the product's PREVIOUS tag, when the tag was
 *                   moved from one ingredient to another (a wrong tag corrected).
 */
class PurchaseLineRestamp
{
    private const POSTED = [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2];

    /**
     * What a "count the old bills too" would do for this product — nothing is written.
     *
     * @return array|null  null when there is nothing to offer
     */
    public function preview(VendorProductModel $product, ?int $fromIngredientId = null): ?array
    {
        $tag  = (int) $product->ingredient_id;
        $pack = (float) $product->pack_qty_base;
        if (!$tag || $pack <= 0) {
            return null;
        }
        $ingredient = IngredientModel::find($tag);
        if (!$ingredient) {
            return null;
        }

        $uncounted = $this->summarise($this->candidates($product, null));
        $moved     = null;
        if ($fromIngredientId && $fromIngredientId !== $tag) {
            $from  = IngredientModel::find($fromIngredientId);
            $moved = $this->summarise($this->candidates($product, $fromIngredientId));
            $moved['from_id']   = $fromIngredientId;
            $moved['from_name'] = $from->name ?? 'another ingredient';
            if ($moved['n'] === 0) {
                $moved = null;
            }
        }

        if ($uncounted['n'] === 0 && !$moved) {
            return null;
        }

        return [
            'product_id'      => (int) $product->id,
            'product_name'    => $product->product_name,
            'ingredient_id'   => $tag,
            'ingredient_name' => $ingredient->name,
            'uncounted'       => $uncounted,
            'moved'           => $moved,
            // Roman Urdu, for the phone's alert — one sentence per kind, numbers included.
            'message'         => $this->message($product->product_name, $ingredient->name, $uncounted, $moved),
        ];
    }

    /**
     * Stamp the accepted lines. Returns how many lines were written.
     *
     * @return array{uncounted:int, moved:int}
     */
    public function apply(VendorProductModel $product, ?int $fromIngredientId, bool $includeMoved): array
    {
        $tag  = (int) $product->ingredient_id;
        $pack = (float) $product->pack_qty_base;
        if (!$tag || $pack <= 0) {
            return ['uncounted' => 0, 'moved' => 0];
        }

        $done = ['uncounted' => 0, 'moved' => 0];
        DB::transaction(function () use ($product, $tag, $pack, $fromIngredientId, $includeMoved, &$done) {
            $done['uncounted'] = $this->stamp($this->candidates($product, null), $tag, $pack);
            if ($includeMoved && $fromIngredientId && $fromIngredientId !== $tag) {
                $done['moved'] = $this->stamp($this->candidates($product, $fromIngredientId), $tag, $pack);
            }
        });

        if ($done['uncounted'] + $done['moved'] > 0) {
            try {
                AuditLogger::log(
                    'purchase_lines_restamped', 'vendor_product', (int) $product->id, $product->product_name,
                    ['ingredient_id' => ['old' => $fromIngredientId, 'new' => $tag],
                     'lines' => ['old' => null, 'new' => $done]],
                    null, 'Earlier bill lines counted towards the ingredient at the user\'s request'
                );
            } catch (\Throwable $e) {
                // the stamp is the fact; the audit row is a nicety
            }
        }
        return $done;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * The product's posted purchase lines that would be (re)stamped: unstamped ones
     * when $ingredientId is null, else the ones stamped with that ingredient. A line
     * typed in a different unit from the product's is skipped — its quantity cannot
     * be converted honestly.
     */
    private function candidates(VendorProductModel $product, ?int $ingredientId)
    {
        $q = DB::table('t_fin_vendor_purchase_items as i')
            ->join('t_fin_ledger as l', 'l.id', '=', 'i.ledger_id')
            ->where('i.vendor_product_id', $product->id)
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED)
            ->where('i.quantity', '>', 0)
            ->where('i.line_total', '>', 0);
        if ($ingredientId === null) {
            $q->whereNull('i.ingredient_id');
        } else {
            $q->where('i.ingredient_id', $ingredientId);
        }
        $rows = $q->select('i.id', 'i.unit', 'i.quantity', 'i.line_total', 'l.transaction_date')
            ->orderBy('l.transaction_date')
            ->get();

        $productUnit = VendorUnits::canonical($product->unit);
        return $rows->filter(function ($r) use ($productUnit) {
            return $r->unit === null || $r->unit === '' || VendorUnits::canonical($r->unit) === $productUnit;
        })->values();
    }

    private function summarise($rows): array
    {
        $n = $rows->count();
        return [
            'n'     => $n,
            'total' => round((float) $rows->sum('line_total'), 2),
            'first' => $n ? substr((string) $rows->first()->transaction_date, 0, 10) : null,
            'last'  => $n ? substr((string) $rows->last()->transaction_date, 0, 10) : null,
        ];
    }

    private function stamp($rows, int $tag, float $pack): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $n += DB::table('t_fin_vendor_purchase_items')->where('id', $r->id)->update([
                'ingredient_id' => $tag,
                'qty_base'      => round((float) $r->quantity * $pack, 3),
            ]);
        }
        return $n;
    }

    /** "3 Oct" / "12 Jul – 3 Oct" */
    private function when(array $s): string
    {
        $f = Carbon::parse($s['first'])->format('j M');
        $l = Carbon::parse($s['last'])->format('j M');
        return $f === $l ? $f : "$f – $l";
    }

    private function message(string $product, string $ingredient, array $uncounted, ?array $moved): string
    {
        $parts = [];
        if ($uncounted['n'] > 0) {
            $parts[] = sprintf('%s ke %d purane bill (%s, Rs %s) abhi %s mein nahi gin rahe.',
                $product, $uncounted['n'], $this->when($uncounted), number_format($uncounted['total']), $ingredient);
        }
        if ($moved) {
            $parts[] = sprintf('%d bill (%s, Rs %s) abhi %s mein ginay hain.',
                $moved['n'], $this->when($moved), number_format($moved['total']), $moved['from_name']);
        }
        $parts[] = sprintf('"Haan" dabane se yeh sab %s ki qeemat aur stock mein shamil ho jayenge. "Nahi" se sirf aage ke bills ginenge.', $ingredient);
        return implode(' ', $parts);
    }
}
