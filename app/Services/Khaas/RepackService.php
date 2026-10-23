<?php

namespace App\Services\Khaas;

use App\Models\CRM\ProductModel;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\Khaas\RecipeFamilyModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🔁 REPACK — warehouse packs of one size opened and packed again as another size of the SAME
 * recipe family (owner, Oct-19-2026: "all made as 12 pieces, later some need to be 8 pieces").
 *
 * ⭐ PIECES ARE CONSERVED, exactly:  from_packs × from_pieces = to_packs × to_pieces + loose
 *   Loose pieces are DECLARED (left over, not stocked anywhere) and must be fewer than one new
 *   pack — otherwise they are another pack.
 *
 * ⚠ A repack is NOT production: nothing is cooked, so it must not consume ingredients or count
 *   as "made". It is written as two warehouse ADJUSTMENTS (reference_type 'repack') — never as
 *   stock_in (which is what triggers recipe consumption and counts as made). Month Review shows
 *   it on its own line ("repacked"), apart from counts and corrections.
 *
 * Web (Khaas Products card) and phone (Products card) both call this through the same endpoint.
 */
class RepackService
{
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = RecipeFamilyService::ready() && Schema::hasTable('t_crm_khaas_repack');
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * What a repack screen needs for one product: the OTHER sizes of its family, each with its
     * pieces and warehouse stock, and this product's own stock.
     */
    public function options(int $productId): array
    {
        $p = ProductModel::find($productId);
        if (!$p) {
            throw new \InvalidArgumentException('That product no longer exists.');
        }
        if (!self::ready() || !$p->recipe_family_id || !$p->pieces_per_pack) {
            return ['can_repack' => false, 'reason' => 'Only a pack that belongs to a recipe family can be repacked into another size.',
                    'from' => null, 'to' => []];
        }
        $sizes = (new RecipeFamilyService())->sizesFor($productId);
        $bu = (int) ($p->business_unit_id ?: RecipeFamilyService::FROZEN_BU);
        $stock = fn (int $pid) => (int) (WarehouseInventoryModel::where('product_id', $pid)->where('business_unit_id', $bu)->sum('quantity') ?? 0);

        $from = ['product_id' => (int) $p->id, 'title' => $p->title, 'label' => RecipeFamilyService::sizeLabel((int) $p->pieces_per_pack),
                 'pieces_per_pack' => (int) $p->pieces_per_pack, 'warehouse' => $stock((int) $p->id)];
        $to = [];
        foreach ($sizes as $s) {
            if ((int) $s['product_id'] === (int) $p->id) {
                continue;
            }
            $to[] = $s + ['warehouse' => $stock((int) $s['product_id'])];
        }
        return [
            'can_repack' => (bool) $to && $from['warehouse'] > 0,
            'reason'     => !$to ? 'Its family has no other size to repack into.'
                : ($from['warehouse'] <= 0 ? 'There are no packs of it in the warehouse.' : null),
            'from'       => $from,
            'to'         => $to,
        ];
    }

    /**
     * The whole packs a number of packs makes in another size, and what is left over — what the
     * screen fills in as he types.  3 × 12 → 8: 36 pieces = 4 packs of 8 + 4 loose.
     */
    public static function plan(int $fromPacks, int $fromPieces, int $toPieces): array
    {
        $pieces = max(0, $fromPacks) * max(0, $fromPieces);
        $toPacks = $toPieces > 0 ? intdiv($pieces, $toPieces) : 0;
        return ['pieces' => $pieces, 'to_packs' => $toPacks, 'loose' => $pieces - $toPacks * $toPieces];
    }

    /** Do it — all or nothing. */
    public function repack(int $fromProductId, int $fromPacks, int $toProductId, int $toPacks, int $loose,
                           ?string $note, ?int $userId): array
    {
        if (!self::ready()) {
            throw new \InvalidArgumentException('Repacking is not set up on this server yet (the SQL has not been run).');
        }
        $from = ProductModel::find($fromProductId);
        $to   = ProductModel::find($toProductId);
        if (!$from || !$to) {
            throw new \InvalidArgumentException('That product no longer exists.');
        }
        if ((int) $from->id === (int) $to->id) {
            throw new \InvalidArgumentException('Pick a different size to repack into.');
        }
        if (!$from->recipe_family_id || !$from->pieces_per_pack || (int) $from->recipe_family_id !== (int) $to->recipe_family_id || !$to->pieces_per_pack) {
            throw new \InvalidArgumentException('Both packs must be sizes of the same recipe family, each with its pieces set.');
        }
        if (!$to->is_active) {
            throw new \InvalidArgumentException("{$to->title} is switched off — switch it on before repacking into it.");
        }
        if ($fromPacks < 1) {
            throw new \InvalidArgumentException('How many packs are being opened? At least 1.');
        }
        if ($toPacks < 0 || $loose < 0) {
            throw new \InvalidArgumentException('Packs and loose pieces are whole numbers, 0 or more.');
        }
        $pf = (int) $from->pieces_per_pack;
        $pt = (int) $to->pieces_per_pack;
        $plan = self::plan($fromPacks, $pf, $pt);
        if ($fromPacks * $pf !== $toPacks * $pt + $loose) {
            throw new \InvalidArgumentException(sprintf(
                '%d × %d pieces = %d pieces, but %d × %d + %d loose = %d. Pieces must add up: that makes %d pack%s of %d%s.',
                $fromPacks, $pf, $plan['pieces'], $toPacks, $pt, $loose, $toPacks * $pt + $loose,
                $plan['to_packs'], $plan['to_packs'] === 1 ? '' : 's', $pt,
                $plan['loose'] ? " with {$plan['loose']} loose" : ''
            ));
        }
        if ($loose >= $pt) {
            throw new \InvalidArgumentException("{$loose} loose pieces are enough for another pack of {$pt} — pack it, or open fewer.");
        }
        if ($toPacks < 1) {
            throw new \InvalidArgumentException("{$fromPacks} × {$pf} pieces do not make one pack of {$pt}.");
        }

        $bu = (int) ($from->business_unit_id ?: RecipeFamilyService::FROZEN_BU);

        return DB::transaction(function () use ($from, $to, $fromPacks, $toPacks, $loose, $pf, $pt, $note, $userId, $bu) {
            $fromVariant = $from->variants()->first()?->id;
            $toVariant   = $to->variants()->first()?->id;
            $fromInv = WarehouseInventoryModel::getOrCreate((int) $from->id, $fromVariant, $bu);
            $fromInv = WarehouseInventoryModel::whereKey($fromInv->id)->lockForUpdate()->first();
            if ((int) $fromInv->quantity < $fromPacks) {
                throw new \InvalidArgumentException(sprintf('Only %d pack%s of %s in the warehouse.',
                    (int) $fromInv->quantity, (int) $fromInv->quantity === 1 ? '' : 's', $from->title));
            }

            $id = DB::table('t_crm_khaas_repack')->insertGetId([
                'business_unit_id' => $bu,
                'family_id'        => (int) $from->recipe_family_id,
                'from_product_id'  => (int) $from->id,
                'from_packs'       => $fromPacks,
                'to_product_id'    => (int) $to->id,
                'to_packs'         => $toPacks,
                'loose_pieces'     => $loose,
                'notes'            => $note ? mb_substr($note, 0, 255) : null,
                'created_by'       => $userId,
                'created_at'       => now(),
            ]);

            $story = sprintf('Repack #%d: %d × %d pcs → %d × %d pcs%s', $id, $fromPacks, $pf, $toPacks, $pt,
                $loose ? " (+{$loose} loose)" : '');
            $outLog = $fromInv->adjustQuantity(-$fromPacks, 'adjustment', $story, 'repack', $id);
            $toInv  = WarehouseInventoryModel::getOrCreate((int) $to->id, $toVariant, $bu);
            $inLog  = $toInv->adjustQuantity($toPacks, 'adjustment', $story, 'repack', $id);

            DB::table('t_crm_khaas_repack')->where('id', $id)->update([
                'from_log_id' => $outLog->id ?? null,
                'to_log_id'   => $inLog->id ?? null,
            ]);

            Log::info('Frozen repack', ['id' => $id, 'from' => $from->id, 'to' => $to->id,
                'from_packs' => $fromPacks, 'to_packs' => $toPacks, 'loose' => $loose, 'by' => $userId]);

            // Oct-19 device walk: the full pack titles made a five-line dialog — say it by family and size
            $famName = RecipeFamilyModel::where('id', $from->recipe_family_id)->value('name') ?: $from->title;
            $fromNow = (int) $fromInv->fresh()->quantity;
            $toNow   = (int) $toInv->fresh()->quantity;
            return [
                'repack_id' => $id,
                'message'   => sprintf('%s: opened %d × %d pcs, packed %d × %d pcs%s. Warehouse now %d × %d pcs, %d × %d pcs.',
                    $famName, $fromPacks, $pf, $toPacks, $pt,
                    $loose ? " — {$loose} loose piece" . ($loose === 1 ? '' : 's') . ' left over' : '',
                    $fromNow, $pf, $toNow, $pt),
                'from_warehouse' => $fromNow,
                'to_warehouse'   => $toNow,
            ];
        });
    }
}
