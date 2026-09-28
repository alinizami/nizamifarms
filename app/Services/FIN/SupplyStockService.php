<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\SupplyBatchModel;
use App\Models\FIN\SupplyLogModel;
use App\Models\FIN\SupplyPacketModel;
use App\Models\FIN\SupplyProductModel;
use App\Models\FIN\SupplyTakeoutLegModel;
use App\Models\FIN\SupplyTakeoutModel;
use App\Models\Request\RequestCategoryModel;
use App\Models\Request\RequestModel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STORAGE (SUPPLIES STOCK) — the one engine.
 *
 * ⭐⭐ THE RULE THE WHOLE FEATURE RESTS ON
 * The paying account moves EXACTLY ONCE, when the stock is bought:
 *
 *      book in   paying account  ->  SUPPLIES_STOCK   type 'supply_purchase'
 *      take out  SUPPLIES_STOCK  ->  EXP_<category>   type 'expense' (via a request)
 *
 * A take-out therefore NEVER names the paying account as its payment source — doing
 * so would make BalancePostingService take the cash a second time. The paying account
 * is carried in the take-out's DESCRIPTION instead, so every screen that prints a
 * description still shows where the money originally came from, and no screen has to
 * change. The same reason `receiving_account_id` stays NULL on a take-out: the
 * per-bank outflow was already counted at purchase.
 *
 * ⭐ COST ALLOCATION — the remainder rule
 * Packets 1..N-1 are rounded to the paisa and the LAST one takes whatever is left, so
 * SUM(packet.cost) == batch.total_cost to the paisa. For pieces, the take-out that
 * empties a batch takes `cost_remaining` rather than n x unit_cost. Never n x rounded:
 * that is how a batch ends up a few rupees short of the money that actually left.
 *
 * ⭐ FIFO
 * Oldest batch first, always — so a cheaper old batch is consumed before a dearer new
 * one, and the rupee figure on a packet is the price actually paid for it.
 */
class SupplyStockService
{
    /** The asset account unused packaging sits in. Created by supplies_storage_sep2026.sql. */
    public const STOCK_ACCOUNT_CODE = 'SUPPLIES_STOCK';

    /** Deliberately NOT 'expense' — invisible to every P&L query by construction. */
    public const LEDGER_TYPE_PURCHASE = 'supply_purchase';

    /** t_fin_config switch: '1' = take-outs queue for approval, '0' = post immediately. */
    public const APPROVAL_SWITCH_KEY = 'supply_takeout_requires_approval';

    /** Take-outs are ordinary expense requests, so they use the ordinary expense category. */
    public const EXPENSE_CATEGORY_CODE = 'expense';

    /** Below this a packet is not worth billing — the request would be refused anyway. */
    private const MIN_BILLABLE = 0.01;

    /**
     * ⭐ Tray-vs-packets drift, in kg. A bale weighed 26.97 kg on the tray but the packets
     * inside it will sum to something slightly different — they were weighed separately,
     * on the same scale, with the same rounding. A purchase left holding less than this is
     * swept into the take-out that got it there rather than lingering at the head of the
     * FIFO queue forever, splitting every later take-out in two for no reason.
     *
     * WEIGHT ONLY. Pieces are whole numbers and do not drift, so sweeping them would
     * silently hand out cups nobody asked for — see residueFor().
     */
    private const RESIDUE_KG = 0.3;

    /** How big a sliver may be swept, for this product. Zero for anything counted. */
    private function residueFor(SupplyProductModel $product): float
    {
        return $product->mode === SupplyProductModel::MODE_WEIGHT ? self::RESIDUE_KG : 0.0;
    }

    /**
     * How far PAST the pool a last packet may reach before it is refused — the same drift,
     * measured the other way. The pool says 1.23 kg left, the last packet in the bale
     * weighs 1.5; refusing it would strand both the packet and the rupees forever.
     */
    public function toleranceFor(SupplyProductModel $product): float
    {
        return $product->mode === SupplyProductModel::MODE_WEIGHT ? self::RESIDUE_KG : 0.0;
    }

    // -----------------------------------------------------------------------------
    // BOOK IN
    // -----------------------------------------------------------------------------

    /**
     * Record a purchase: N packets (or a piece count) + one total price + which
     * account paid. All-or-nothing.
     *
     * @param array $in product_id, purchase_date, total_cost, stock_only,
     *                  payment_source_account_id, receiving_account_id, note,
     *                  bill_image, client_uuid, packets[] {qty, barcode, plu, source},
     *                  pieces_qty
     */
    public function bookBatch(array $in, int $userId): SupplyBatchModel
    {
        // A retried Save (phone lost the reply) must return the SAME batch, never book
        // a second one. Checked before the transaction so the common case is one read.
        //
        // ⚠ The pre-check alone is not enough. Two Saves landing together both read
        // "nothing there" and both go on to INSERT; `uq_sup_batch_uuid` then throws on
        // the loser, which the controller used to surface as "Could not save. Nothing was
        // recorded." — a lie, because the winner DID save, and a lie that invites a third
        // attempt. Catching the duplicate key and returning the winning batch makes the
        // whole call idempotent however the two requests interleave.
        if (!empty($in['client_uuid'])) {
            $existing = SupplyBatchModel::where('client_uuid', $in['client_uuid'])->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return $this->bookBatchTransaction($in, $userId);
        } catch (QueryException $e) {
            if (!empty($in['client_uuid']) && $this->isDuplicateKey($e)) {
                $won = SupplyBatchModel::where('client_uuid', $in['client_uuid'])->first();
                if ($won) {
                    Log::info('Storage book-in: duplicate save collapsed onto the first batch', [
                        'client_uuid' => $in['client_uuid'],
                        'batch_id' => $won->id,
                    ]);

                    return $won;
                }
            }
            throw $e;
        }
    }

    /** MySQL/MariaDB duplicate-entry, whichever driver code the host reports. */
    private function isDuplicateKey(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || $e->getCode() === '23000';
    }

    private function bookBatchTransaction(array $in, int $userId): SupplyBatchModel
    {
        return DB::transaction(function () use ($in, $userId) {
            /** @var SupplyProductModel $product */
            $product = SupplyProductModel::findOrFail($in['product_id']);

            $stockOnly = !empty($in['stock_only']);
            $totalCost = $stockOnly ? 0.0 : round((float) ($in['total_cost'] ?? 0), 2);

            // ⚠ BOOKING IN IS UNCHANGED BY ROUND 3 — booksPackets(), not usesPackets().
            // A weight product still arrives as packet rows (what was weighed onto the
            // shelf); it is only TAKING OUT that now draws from the pool instead.
            $packets = $product->booksPackets() ? array_values($in['packets'] ?? []) : [];
            $qtyTotal = $product->booksPackets()
                ? round(array_sum(array_map(fn ($p) => (float) $p['qty'], $packets)), 3)
                : round((float) ($in['pieces_qty'] ?? 0), 3);

            if ($qtyTotal <= 0) {
                throw new \RuntimeException('Nothing to book in.');
            }

            $batch = SupplyBatchModel::create([
                'product_id' => $product->id,
                'mode' => $product->mode,
                'packet_count' => $product->booksPackets() ? count($packets) : null,
                'qty_total' => $qtyTotal,
                'qty_remaining' => $qtyTotal,
                'total_cost' => $totalCost,
                'cost_remaining' => $totalCost,
                'unit_cost' => $qtyTotal > 0 ? round($totalCost / $qtyTotal, 4) : null,
                'purchase_date' => $in['purchase_date'] ?? now()->toDateString(),
                'payment_source_account_id' => $stockOnly ? null : ($in['payment_source_account_id'] ?? null),
                'receiving_account_id' => $stockOnly ? null : ($in['receiving_account_id'] ?? null),
                'status' => $stockOnly ? SupplyBatchModel::STATUS_STOCK_ONLY : SupplyBatchModel::STATUS_CONFIRMED,
                'note' => $in['note'] ?? null,
                'bill_image' => $in['bill_image'] ?? null,
                'booked_by' => $userId,
                'client_uuid' => $in['client_uuid'] ?? null,
            ]);

            if ($product->booksPackets()) {
                $this->createPackets($batch, $product, $packets, $totalCost, $qtyTotal, $userId);
            } else {
                // Pieces have no packet rows — the batch counters ARE the stock.
                $this->log(SupplyLogModel::ACTION_IN, $product, $batch, null, null,
                    $qtyTotal, $product->takeoutUnit(), $totalCost, 'manual', $userId);
            }

            if (!$stockOnly && $totalCost > 0) {
                $this->postPurchase($batch, $product, $userId);
            }

            return $batch->fresh();
        });
    }

    /**
     * Allocate the batch price across the packets and write them.
     * The last packet takes the remainder so the packets sum to the price exactly.
     */
    private function createPackets(
        SupplyBatchModel $batch,
        SupplyProductModel $product,
        array $packets,
        float $totalCost,
        float $qtyTotal,
        int $userId
    ): void {
        $count = count($packets);
        $allocated = 0.0;

        foreach ($packets as $i => $p) {
            // A SCAN product's packets are all the same fixed code, and each is one
            // unit. Normalised here rather than only in the controller so the rule
            // holds for every caller — otherwise a packet saved with a null barcode
            // can never be found again by scanning it.
            if ($product->mode === SupplyProductModel::MODE_SCAN) {
                $p['qty'] = 1;
                $p['barcode'] = $p['barcode'] ?? $product->barcode;
            }

            $qty = round((float) $p['qty'], 3);

            if ($totalCost <= 0) {
                $cost = 0.0;
            } elseif ($i === $count - 1) {
                // ⭐ The remainder — never round the last one independently.
                $cost = round($totalCost - $allocated, 2);
            } else {
                $cost = round($totalCost * $qty / $qtyTotal, 2);
                $allocated += $cost;
            }

            $packet = SupplyPacketModel::create([
                'batch_id' => $batch->id,
                'product_id' => $product->id,
                'seq' => $i + 1,
                'barcode' => $p['barcode'] ?? null,
                'plu' => $p['plu'] ?? null,
                'qty' => $qty,
                'cost' => $cost,
                'status' => SupplyPacketModel::STATUS_IN_STOCK,
            ]);

            // The label this packet was weighed in under — so intake is as answerable as
            // take-out is. `p['barcode']` is the server's own re-decode, not the client's.
            $this->log(SupplyLogModel::ACTION_IN, $product, $batch, $packet, null,
                $qty, $product->takeoutUnit(), $cost, $p['source'] ?? 'scan', $userId,
                null, $p['barcode'] ?? null);
        }
    }

    /**
     * The money leg of a purchase: paying account -> SUPPLIES_STOCK.
     *
     * Booked approved outright, like an asset purchase — only `manage_supplies_storage`
     * holders can reach this, and both of them hold approval level 1 anyway.
     */
    private function postPurchase(SupplyBatchModel $batch, SupplyProductModel $product, int $userId): void
    {
        $source = AccountModel::find($batch->payment_source_account_id);
        if (!$source) {
            throw new \RuntimeException('Choose which account paid for this stock.');
        }

        $stock = $this->stockAccount();
        if (!$stock) {
            throw new \RuntimeException('The Storage stock account is missing — run supplies_storage_sep2026.sql.');
        }

        $isBank = $source->account_category === AccountModel::CATEGORY_BANK;
        $bankId = $isBank ? $batch->receiving_account_id : null;

        $description = 'Storage stock: ' . $product->name
            . ' — ' . $this->quantityPhrase($batch->qty_total, $product)
            . ' · batch #' . $batch->id;

        if ($bankId) {
            $short = \App\Models\FIN\OnlineReceivingAccountModel::find($bankId)?->short_code;
            if ($short) {
                $description .= ' · via ' . $short;
            }
        }

        $ledger = LedgerModel::create([
            'transaction_date' => $batch->purchase_date,
            'transaction_type' => self::LEDGER_TYPE_PURCHASE,
            'description' => $description,
            'from_account_id' => $source->id,
            'to_account_id' => $stock->id,
            'amount' => $batch->total_cost,
            'mode' => $isBank ? LedgerModel::MODE_ONLINE : null,
            'receiving_account_id' => $bankId,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'approved_by' => $userId,
            'approval_date' => now(),
            'business_unit_id' => $product->business_unit_id ?? 1,
            'created_by' => $userId,
            'comments' => 'Paid from: ' . $source->account_name
                . '. Not an expense — it becomes one packet at a time, as the stock is used.',
        ]);

        (new BalancePostingService())->apply($ledger);

        $batch->ledger_id = $ledger->id;
        $batch->save();
    }

    // -----------------------------------------------------------------------------
    // TAKE OUT
    // -----------------------------------------------------------------------------

    /**
     * Take stock out for use and raise the expense request for it.
     *
     * @param array $in product_id, packet_id (weight/scan) | qty (pieces), source, note
     */
    public function takeOut(array $in, int $userId): SupplyTakeoutModel
    {
        return DB::transaction(function () use ($in, $userId) {
            /** @var SupplyProductModel $product */
            $product = SupplyProductModel::findOrFail($in['product_id']);

            if (!$product->is_active) {
                throw new \RuntimeException($product->name . ' is switched off — ask Taimur or Shabib.');
            }

            // ⭐ Round 3: only a SCAN product takes an identified packet. Weight and pieces
            //    both draw from the pool — the same engine, the same FIFO, the same legs.
            $legs = $product->usesPackets()
                ? $this->consumePacket($product, (int) ($in['packet_id'] ?? 0), $userId)
                : $this->consumeFromPool(
                    $product,
                    round((float) ($in['qty'] ?? 0), 3),
                    $this->toleranceFor($product)
                );

            // The sweep and the last-packet tolerance can both move the quantity off what was
            // asked for, so the take-out records what ACTUALLY left the shelf: the legs.
            $qty  = round(array_sum(array_column($legs, 'qty')), 3);
            $cost = round(array_sum(array_column($legs, 'cost')), 2);

            // A stock_only batch (opening stock, already expensed) bills nothing, and
            // neither does a packet whose share rounds below a paisa.
            $billable = $cost >= self::MIN_BILLABLE;

            $takeout = SupplyTakeoutModel::create([
                'product_id' => $product->id,
                'qty' => $qty,
                'unit' => $product->takeoutUnit(),
                'cost' => $cost,
                'source' => $in['source'] ?? 'scan',
                // ⚠ The code that was actually read, or NULL when the weight was typed.
                // Recorded because on Sep-17 nobody could tell which of two very different
                // things had happened, and it also feeds the "same label twice" guard.
                'scanned_barcode' => $in['scanned_barcode'] ?? null,
                'status' => $billable ? SupplyTakeoutModel::STATUS_PENDING : SupplyTakeoutModel::STATUS_NO_CHARGE,
                'note' => $in['note'] ?? null,
                'taken_by' => $userId,
                'taken_at' => now(),
                'settled_at' => $billable ? null : now(),
            ]);

            foreach ($legs as $leg) {
                SupplyTakeoutLegModel::create([
                    'takeout_id' => $takeout->id,
                    'batch_id' => $leg['batch_id'],
                    'packet_id' => $leg['packet_id'] ?? null,
                    'qty' => $leg['qty'],
                    'cost' => $leg['cost'],
                    'created_at' => now(),
                ]);

                if (!empty($leg['packet_id'])) {
                    SupplyPacketModel::where('id', $leg['packet_id'])->update([
                        'takeout_id' => $takeout->id,
                        'consumed_by' => $userId,
                    ]);
                }

                $this->log(SupplyLogModel::ACTION_OUT, $product,
                    SupplyBatchModel::find($leg['batch_id']),
                    !empty($leg['packet_id']) ? SupplyPacketModel::find($leg['packet_id']) : null,
                    $takeout, $leg['qty'], $product->takeoutUnit(), $leg['cost'],
                    $in['source'] ?? 'scan', $userId, null, $in['scanned_barcode'] ?? null);
            }

            if ($billable) {
                $this->raiseExpenseRequest($takeout, $product, $legs, $userId);
            }

            return $takeout->fresh();
        });
    }

    /**
     * Consume one identified packet. Row-locked: two phones scanning the same label at
     * the same moment get two different packets, or the second is told there are none
     * left — never the same packet twice.
     */
    private function consumePacket(SupplyProductModel $product, int $packetId, int $userId): array
    {
        /** @var SupplyPacketModel|null $packet */
        $packet = SupplyPacketModel::where('id', $packetId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$packet) {
            throw new \RuntimeException('That packet is not in Storage.');
        }

        // ⭐ Sep-26: every packet of a packet product is the same thing under the same label,
        // and the confirm card only ever offered "the oldest one". If somebody else took that
        // very packet between the scan and the tap, the next oldest IS what this person is
        // holding — so take that one instead of telling them to scan again for ever.
        if ($packet->status !== SupplyPacketModel::STATUS_IN_STOCK) {
            $next = SupplyPacketModel::query()
                ->select('t_fin_supply_packet.*')
                ->join('t_fin_supply_batch as b', 'b.id', '=', 't_fin_supply_packet.batch_id')
                ->where('t_fin_supply_packet.product_id', $product->id)
                ->where('t_fin_supply_packet.status', SupplyPacketModel::STATUS_IN_STOCK)
                ->where('b.status', '!=', SupplyBatchModel::STATUS_VOIDED)
                ->orderBy('b.purchase_date')->orderBy('b.id')->orderBy('t_fin_supply_packet.seq')
                ->lockForUpdate()
                ->first();
            if (!$next) {
                throw new \RuntimeException('No ' . $product->name . ' left in Storage — ask Taimur or Shabib to book the new stock.');
            }
            $packet = SupplyPacketModel::where('id', $next->id)->lockForUpdate()->first();
        }

        $batch = SupplyBatchModel::where('id', $packet->batch_id)->lockForUpdate()->first();

        $packet->status = SupplyPacketModel::STATUS_CONSUMED;
        $packet->consumed_at = now();
        $packet->consumed_by = $userId;
        $packet->save();

        $batch->qty_remaining = round(max(0, (float) $batch->qty_remaining - (float) $packet->qty), 3);
        $batch->cost_remaining = round(max(0, (float) $batch->cost_remaining - (float) $packet->cost), 2);
        $batch->save();

        return [[
            'batch_id' => $batch->id,
            'packet_id' => $packet->id,
            'qty' => (float) $packet->qty,
            'cost' => (float) $packet->cost,
        ]];
    }

    /**
     * ⭐⭐ THE POOL ENGINE. Draw `$want` out of a product's running stock, oldest purchase
     * first, each leg carrying that purchase's own rate.
     *
     * Was `consumePieces` (cups, counted). Round 3 routes WEIGHT through it too, which is
     * what makes the real flow work: a bale is weighed once on a tray and booked as one
     * label, then the small packets inside it are scanned out one at a time. Before this,
     * one scanned label was one indivisible packet, so scanning a 1.5 kg inner packet could
     * only take the whole 26.97 kg bale — which is exactly what happened on Sep-17-2026.
     *
     * May cross a purchase boundary (owner ruling: allowed, and silently) — 1.5 kg when the
     * oldest bale has 0.27 kg left produces two legs at two rates, still ONE request.
     *
     * ⚠ THE RESIDUE SWEEP IS NOT AN EDGE CASE HERE. The owner keeps buying before the pool
     * runs out, so purchases always overlap. A tray weighs 26.97 kg but the packets inside
     * it sum to 26.9 or 27.05 — real drift, every bale. Without the sweep the older purchase
     * would sit at the head of the FIFO queue forever holding 70 g that no packet ever
     * matches, and EVERY take-out after it would split into two legs for no reason. So a
     * purchase left with a sliver is emptied into the take-out that got it there.
     *
     * @param  float      $want       kg (weight) or pieces (pieces)
     * @param  float|null $tolerance  how far past the pool a last packet may reach; null = strict
     */
    private function consumeFromPool(SupplyProductModel $product, float $want, ?float $tolerance = null): array
    {
        if ($want <= 0) {
            throw new \RuntimeException($product->mode === SupplyProductModel::MODE_WEIGHT
                ? 'How much are you taking out?'
                : 'How many are you taking out?');
        }

        $batches = SupplyBatchModel::where('product_id', $product->id)
            ->whereIn('status', [SupplyBatchModel::STATUS_CONFIRMED, SupplyBatchModel::STATUS_STOCK_ONLY])
            ->where('qty_remaining', '>', 0)
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = round($batches->sum(fn ($b) => (float) $b->qty_remaining), 3);
        $slack = $tolerance ?? 0.0;

        if ($available <= 0) {
            throw new \RuntimeException('No ' . $product->name . ' left in Storage — ask Taimur or Shabib to book the new stock.');
        }

        if ($available < $want - 0.0005) {
            // ⭐ LAST PACKET SHORT. The same tray-vs-packets drift, the other way round: the
            //    pool says 1.23 kg but the last packet in the bale weighs 1.5. Refusing it
            //    would strand the packet AND the remaining rupees forever, so within the
            //    tolerance the take-out simply takes what is actually there.
            if ($slack > 0 && ($want - $available) <= $slack) {
                $want = $available;
            } else {
                throw new \RuntimeException(
                    'Only ' . $this->trimNumber($available) . ' ' . $this->poolUnit($product)
                    . ' of ' . $product->name . ' left in Storage.'
                );
            }
        }

        $legs = [];
        $left = $want;

        foreach ($batches as $batch) {
            if ($left <= 0.0005) {
                break;
            }

            $remaining = (float) $batch->qty_remaining;
            $take = min($left, $remaining);

            // ⭐ The sweep: this take-out would leave a sliver behind, so take the sliver
            //    too. The take-out's recorded quantity is the SUM OF ITS LEGS (see takeOut),
            //    so it honestly reads 1.6 kg rather than the 1.5 kg that was scanned — which
            //    is the truth: 1.6 kg physically left the shelf.
            // ⚠ THE EPSILON IS LOAD-BEARING. 1.5 − 1.2 is 0.29999999999999993 in binary
            // floating point, so a bare `<= 0.3` made the sweep fire or not fire on noise —
            // and the preview and the real consumption could then disagree about the same
            // numbers. Comparing with a gram of slack makes "exactly the residue" always
            // sweep, in both places. previewPoolConsumption uses the identical line.
            $residue = $this->residueFor($product);
            if ($residue > 0 && $remaining - $take > 0.0005 && ($remaining - $take) <= $residue + 0.0005) {
                $take = $remaining;
            }

            $emptiesBatch = abs($take - $remaining) < 0.0005;

            // ⭐ The take-out that empties a purchase takes whatever cost is LEFT, so the
            //    purchase lands on exactly zero instead of a few paisa short or over.
            $cost = $emptiesBatch
                ? round((float) $batch->cost_remaining, 2)
                : $this->poolLegCost($product, $batch, $remaining, (float) $batch->cost_remaining, $take);

            $batch->qty_remaining = round($remaining - $take, 3);
            $batch->cost_remaining = round(max(0, (float) $batch->cost_remaining - $cost), 2);
            $batch->save();

            $legs[] = [
                'batch_id' => $batch->id,
                'packet_id' => null,
                'qty' => round($take, 3),
                'cost' => $cost,
            ];

            $left = round($left - $take, 3);
        }

        return $legs;
    }

    /**
     * What consumeFromPool WOULD do, without writing anything.
     *
     * Used by the take-out preview and by the weight-edit dialog, where the figure shown to
     * the manager must be the figure that lands. `$addBack` lets the edit preview put its
     * own legs back on the shelf first, so it prices the new weight against the pool as it
     * will actually be — not against a pool still missing the kg it is about to return.
     *
     * @param  array $addBack  [batch_id => ['qty' => kg, 'cost' => Rs], …]
     */
    public function previewPoolConsumption(
        SupplyProductModel $product,
        float $want,
        array $addBack = [],
        ?float $tolerance = null
    ): array {
        $batches = SupplyBatchModel::where('product_id', $product->id)
            ->whereIn('status', [SupplyBatchModel::STATUS_CONFIRMED, SupplyBatchModel::STATUS_STOCK_ONLY])
            ->orderBy('purchase_date')->orderBy('id')->get();

        $rows = [];
        foreach ($batches as $b) {
            $qty = (float) $b->qty_remaining + (float) ($addBack[$b->id]['qty'] ?? 0);
            $cost = (float) $b->cost_remaining + (float) ($addBack[$b->id]['cost'] ?? 0);
            if ($qty > 0) {
                $rows[] = ['batch' => $b, 'qty' => round($qty, 3), 'cost' => round($cost, 2)];
            }
        }

        $available = round(array_sum(array_column($rows, 'qty')), 3);
        $slack = $tolerance ?? 0.0;
        $capped = false;

        if ($available < $want - 0.0005) {
            if ($slack > 0 && ($want - $available) <= $slack) {
                $want = $available;
                $capped = true;
            } else {
                return ['ok' => false, 'available' => $available, 'legs' => [], 'cost' => 0.0];
            }
        }

        $legs = [];
        $left = $want;
        foreach ($rows as $row) {
            if ($left <= 0.0005) {
                break;
            }
            $take = min($left, $row['qty']);
            // identical to consumeFromPool — see the epsilon note there
            $residue = $this->residueFor($product);
            if ($residue > 0 && $row['qty'] - $take > 0.0005 && ($row['qty'] - $take) <= $residue + 0.0005) {
                $take = $row['qty'];
            }
            $empties = abs($take - $row['qty']) < 0.0005;
            $cost = $empties
                ? round($row['cost'], 2)
                : $this->poolLegCost($product, $row['batch'], $row['qty'], $row['cost'], $take);

            $legs[] = [
                'batch_id' => $row['batch']->id,
                'purchase_date' => optional($row['batch']->purchase_date)->format('d-M'),
                'qty' => round($take, 3),
                'cost' => $cost,
            ];
            $left = round($left - $take, 3);
        }

        // ⚠ The quantity is the SUM OF THE LEGS, never the quantity that was asked for.
        // The residue sweep can take more than was requested (it retires a sliver rather
        // than leave it stranded), and takeOut() records the legs — so returning `$want`
        // here made the preview quote 1.2 kg beside the cost of 1.25 kg. A preview whose
        // figure is not the figure that lands is worse than no preview.
        return [
            'ok' => true,
            'available' => $available,
            'capped' => $capped,
            'qty' => round(array_sum(array_column($legs, 'qty')), 3),
            'requested' => round($want, 3),
            'legs' => $legs,
            'cost' => round(array_sum(array_column($legs, 'cost')), 2),
        ];
    }

    /**
     * The price of ONE leg that does not empty its purchase. consumeFromPool and
     * previewPoolConsumption both call this, so a previewed figure is the figure that lands.
     *
     * ⚠ Sep-26 review: the old line was `round(total × take / qty_total, 2)` with nothing
     * stopping it going past what was left. Each leg rounds on its own, so on a cheap
     * counted item the half-paisa rounds UP every time — 1,000 pcs for Rs 2,345 taken one
     * at a time charged Rs 2,347.65 and left the stock account Rs 2.65 below the shelf.
     *
     *   • pieces — priced from what is LEFT (cost_remaining ÷ qty_remaining), so rounding
     *     corrects itself on the next take and the purchase lands on exactly its price.
     *   • weight — UNCHANGED formula (owner: weighed products must behave exactly as they
     *     do), only capped at what is left. At bag prices the cap never bites in practice.
     */
    private function poolLegCost(
        SupplyProductModel $product,
        SupplyBatchModel $batch,
        float $qtyLeft,
        float $costLeft,
        float $take
    ): float {
        if ($product->mode === SupplyProductModel::MODE_PIECES) {
            return $qtyLeft > 0 ? round($costLeft * $take / $qtyLeft, 2) : 0.0;
        }

        $prorated = round(((float) $batch->total_cost) * $take / max((float) $batch->qty_total, 0.0001), 2);

        return round(min($prorated, max(0.0, $costLeft)), 2);
    }

    /** "kg" or "pcs" — the unit a pooled product's quantity is counted in. */
    public function poolUnit(SupplyProductModel $product): string
    {
        return $product->mode === SupplyProductModel::MODE_WEIGHT ? 'kg' : 'pcs';
    }

    /** 1.250 -> "1.25", 3.000 -> "3" — a number a person would write. */
    private function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    /**
     * The expense request a take-out raises.
     *
     * Built here rather than posted through RequestController::store on purpose: that
     * endpoint runs the payment-source allow-list, which would refuse a store user
     * paying from the stock account. The rules that matter are applied explicitly
     * below instead.
     *
     * `$userId` is who is ACTING (decides self-approval, signs the audit row). Normally that
     * is also the spender and the day is today. A LATER correction that makes a free
     * take-out chargeable passes the original `$expenseDate` and `$requesterId` (the person
     * who took it out) — Sep-26 review: it used to land dated the day of the edit, under
     * the manager's name, so the cost moved into the wrong month.
     */
    private function raiseExpenseRequest(
        SupplyTakeoutModel $takeout,
        SupplyProductModel $product,
        array $legs,
        int $userId,
        ?string $expenseDate = null,
        ?int $requesterId = null
    ): void {
        $category = RequestCategoryModel::where('category_code', self::EXPENSE_CATEGORY_CODE)->first();
        if (!$category) {
            throw new \RuntimeException('The Expense request category is missing.');
        }

        $stock = $this->stockAccount();
        if (!$stock) {
            throw new \RuntimeException('The Storage stock account is missing — run supplies_storage_sep2026.sql.');
        }

        $requiresL1 = (bool) $category->requiresLevel1();
        $requiresL2 = (bool) $category->requiresLevel2();

        // Approval, in this order:
        //   1. the owner's switch is off  -> post immediately, for everyone
        //   2. the scanner holds every level this category needs (Taimur, Shabib)
        //      -> post immediately, exactly as their own expenses behave today
        //   3. otherwise queue it
        $switchOff = !$this->takeoutsNeedApproval();
        $selfApproves = !$switchOff
            && app(SelfApprovalPolicy::class)->canSelfApprove(LedgerModel::TYPE_EXPENSE, $userId);
        $autoApprove = $switchOff || $selfApproves;

        $qtyPhrase = $this->quantityPhrase($takeout->qty, $product);
        $batch = SupplyBatchModel::find($legs[0]['batch_id']);

        $request = RequestModel::create([
            'request_number' => RequestModel::generateRequestNumber(),
            'category_id' => $category->id,
            'requester_user_id' => $requesterId ?? $userId, // the SCANNER is the spender (owner ruling)
            'title' => 'Storage: ' . $product->name . ' ' . $qtyPhrase,
            'description' => $this->takeoutDescription($product, $qtyPhrase, $legs),
            'amount' => $takeout->cost,
            'expense_category' => $product->expense_category_name,
            'expense_date' => $expenseDate ?? now()->toDateString(), // the day it was USED — the whole point
            // ⭐⭐ The money leg. NEVER the batch's paying account: that cash already
            //    left when the stock was bought, and naming it here would take it twice.
            'payment_source_account_id' => $stock->id,
            'receiving_account_id' => null,           // the bank was tagged at purchase
            'business_unit_id' => $product->business_unit_id ?? 1,
            'status' => $autoApprove ? RequestModel::STATUS_APPROVED : RequestModel::STATUS_PENDING,
            'priority' => 'normal',
            'requires_level_1' => $requiresL1,
            'requires_level_2' => $requiresL2,
            'level_1_status' => $requiresL1
                ? ($autoApprove ? RequestModel::APPROVAL_STATUS_APPROVED : RequestModel::APPROVAL_STATUS_PENDING)
                : null,
            'level_2_status' => $requiresL2
                ? ($autoApprove ? RequestModel::APPROVAL_STATUS_APPROVED : RequestModel::APPROVAL_STATUS_PENDING)
                : null,
            // Nothing to settle: no one is out of pocket, the money left at purchase.
            'settlement_status' => 'not_required',
            'submitted_at' => now(),
            'created_by' => $userId,
            'supply_takeout_id' => $takeout->id,
        ]);

        if ($autoApprove) {
            // ⚠ There are NO level_1_approved_by / level_1_approved_at columns on
            // t_req_master — who approved lives in t_req_approval, which is also where
            // every screen reads it from. (RequestController::store passes those four
            // keys to create() and Eloquent silently drops them, so nothing has ever
            // been written by that path either.) Writing the real audit row instead
            // means an auto-approved take-out reads exactly like any other approval.
            $note = $switchOff
                ? 'Auto-approved — Storage take-out approval is switched off.'
                : app(SelfApprovalPolicy::class)->auditNote(LedgerModel::TYPE_EXPENSE, $userId);

            foreach (array_filter([$requiresL1 ? 1 : null, $requiresL2 ? 2 : null]) as $level) {
                \App\Models\Request\RequestApprovalModel::create([
                    'request_id' => $request->id,
                    'approval_level' => $level,
                    'approver_user_id' => $userId,
                    'status' => RequestModel::APPROVAL_STATUS_APPROVED,
                    'comments' => $note,
                    'action_date' => now(),
                    'created_by' => $userId,
                ]);
            }

            $request->completed_at = now();
            $request->remarks = $note;
            $request->save();
        }

        $takeout->request_id = $request->id;
        if ($autoApprove) {
            $takeout->status = SupplyTakeoutModel::STATUS_APPROVED;
            $takeout->settled_at = now();
        }
        $takeout->save();

        if ($autoApprove) {
            // ⚠⚠ Sep-26 review: this was a try/catch around a method that never throws —
            // postExpenseFromRequest catches everything itself and RETURNS success:false.
            // The result was ignored, so a failed post (e.g. no expense account for the
            // category) left the take-out "approved", the stock off the shelf and NO expense:
            // the stock account then sat above the shelf for ever, and nothing said so.
            //
            // Every caller runs inside a transaction, so failing here rolls the WHOLE
            // take-out back — nothing is half-recorded, and the person gets a message
            // they can act on instead of a take-out that silently cost nothing.
            try {
                $result = app(LedgerPostingService::class)->postExpenseFromRequest($request);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage()];
            }
            if (empty($result['success'])) {
                Log::error('Storage take-out: expense post failed — take-out rolled back', [
                    'request_id' => $request->id,
                    'takeout_id' => $takeout->id,
                    'product_id' => $product->id,
                    'expense_category' => $product->expense_category_name,
                    'error' => $result['message'] ?? null,
                ]);
                throw new \RuntimeException(
                    'The expense for this could not be booked (' . ($result['message'] ?? 'unknown error') . '), '
                    . 'so nothing was taken out. Tell Taimur or Shabib — the product\'s expense category may need fixing.'
                );
            }
        }
    }

    // -----------------------------------------------------------------------------
    // RESTORE / VOID
    // -----------------------------------------------------------------------------

    /**
     * Reconcile a take-out with its request's CURRENT status. Idempotent, and safe to
     * call from every door that can decide a request — approve, reject, cancel, and
     * the two delete paths — so no door can be forgotten and none can double-restore.
     */
    public function syncWithRequest(?RequestModel $request): void
    {
        if (!$request || !$request->supply_takeout_id) {
            return;
        }

        $takeout = SupplyTakeoutModel::find($request->supply_takeout_id);
        if (!$takeout) {
            return;
        }

        // ⭐⭐ THE DELETE DOOR — an APPROVED take-out whose expense was deleted afterwards.
        //
        // Found on Sep-17-2026 with real money: Taimur's first take-out (26.97 kg, Rs 24,750)
        // was auto-approved, and the only way back from "approved" is to delete the expense.
        // Every delete door calls this method — but this method gated on isPending(), and a
        // take-out you can delete the expense of is by definition NOT pending. So the delete
        // reversed the ledger (Rs 24,750 back into SUPPLIES_STOCK) and then did nothing here:
        // the packet stayed consumed, the batch stayed empty, and the stock account and the
        // shelf disagreed by exactly the packet's cost. The comment on every delete door
        // promised "puts the packet back"; none of them ever could.
        //
        // ⚠ Restored ONLY once the money has actually gone back. The delete doors reverse
        // the ledger BEFORE they call here, so a cancelled request whose ledger row is still
        // `approved` means somebody cancelled without reversing — and putting the packet
        // back then would double-count it (on the shelf AND in expenses). A take-out with no
        // ledger row at all (the post failed and was logged) moved no money, so it restores.
        if ($takeout->status === SupplyTakeoutModel::STATUS_APPROVED
            && $request->status === RequestModel::STATUS_CANCELLED) {
            $ledger = $request->ledger_transaction_id ? LedgerModel::find($request->ledger_transaction_id) : null;
            if ($ledger && $ledger->approval_status !== LedgerModel::STATUS_REVERSED) {
                Log::warning('Storage take-out: request cancelled but its expense is still posted — packet NOT restored', [
                    'request_id' => $request->id,
                    'takeout_id' => $takeout->id,
                    'ledger_id' => $ledger->id,
                ]);

                return;
            }

            $this->restore(
                $takeout,
                SupplyTakeoutModel::STATUS_UNDONE,
                'Expense deleted' . ($request->rejection_reason ? ' — ' . $request->rejection_reason : ''),
                true
            );

            return;
        }

        if (!$takeout->isPending()) {
            return; // already settled — nothing to do, and nothing to double-restore
        }

        if ($request->status === RequestModel::STATUS_APPROVED) {
            $takeout->status = SupplyTakeoutModel::STATUS_APPROVED;
            $takeout->settled_at = now();
            $takeout->save();
            return;
        }

        if (in_array($request->status, [RequestModel::STATUS_REJECTED, RequestModel::STATUS_CANCELLED], true)) {
            $this->restore(
                $takeout,
                $request->status === RequestModel::STATUS_REJECTED
                    ? SupplyTakeoutModel::STATUS_REJECTED
                    : SupplyTakeoutModel::STATUS_UNDONE,
                $request->rejection_reason
            );
        }
    }

    /**
     * Put a take-out's stock back on the shelf.
     *
     * ⚠ Gated on isUndoable(), NOT isPending(): a `no_charge` take-out (opening stock,
     * already expensed) has no request and never becomes pending, so isPending() left
     * every free packet permanently consumed. Restoring one adds 0.00 to cost_remaining
     * by construction — its legs all cost zero — so no money can move here either way.
     * Still idempotent: the row is re-read under a lock and a settled one returns early.
     *
     * `$expenseDeleted` is the ONE way an APPROVED take-out comes back: its expense has been
     * deleted and the ledger already reversed, so the cost this adds back to the batch is the
     * same cost the reversal just returned to SUPPLIES_STOCK. Only syncWithRequest() passes
     * it, and only after checking the ledger row really is `reversed`.
     */
    public function restore(SupplyTakeoutModel $takeout, string $status, ?string $reason = null, bool $expenseDeleted = false): bool
    {
        // Returns whether stock actually went back — a caller that promises "it is back in
        // Storage" must be able to tell a restore from a no-op (Sep-26 review).
        return DB::transaction(function () use ($takeout, $status, $reason, $expenseDeleted) {
            $fresh = SupplyTakeoutModel::where('id', $takeout->id)->lockForUpdate()->first();
            $allowed = $fresh && ($fresh->isUndoable()
                || ($expenseDeleted && $fresh->status === SupplyTakeoutModel::STATUS_APPROVED));
            if (!$allowed) {
                return false;
            }

            $product = SupplyProductModel::find($fresh->product_id);

            foreach ($fresh->legs()->get() as $leg) {
                $batch = SupplyBatchModel::where('id', $leg->batch_id)->lockForUpdate()->first();
                if ($batch) {
                    $batch->qty_remaining = round((float) $batch->qty_remaining + (float) $leg->qty, 3);
                    $batch->cost_remaining = round((float) $batch->cost_remaining + (float) $leg->cost, 2);
                    $batch->save();
                }

                if ($leg->packet_id) {
                    SupplyPacketModel::where('id', $leg->packet_id)->update([
                        'status' => SupplyPacketModel::STATUS_IN_STOCK,
                        'consumed_at' => null,
                        'consumed_by' => null,
                        'takeout_id' => null,
                    ]);
                }

                $this->log(SupplyLogModel::ACTION_UNDO, $product, $batch,
                    $leg->packet_id ? SupplyPacketModel::find($leg->packet_id) : null,
                    $fresh, (float) $leg->qty, $fresh->unit, (float) $leg->cost, null,
                    auth()->id(), $reason);
            }

            $fresh->status = $status;
            $fresh->settled_at = now();
            $fresh->save();

            return true;
        });
    }

    // -----------------------------------------------------------------------------
    // DELETE / EDIT A TAKE-OUT  (round 3)
    // -----------------------------------------------------------------------------

    /**
     * ⭐ Delete a take-out from the Storage screen — ONE engine, every status.
     *
     * Storage needed its own door. The Expenses page's 🗑 only reaches a take-out that
     * raised an expense and is L2-gated (Taimur alone); a `no_charge` take-out off opening
     * stock has no expense at all, and the owner asked for Shabib to be able to fix a
     * mistake too. Gated on `manage_supplies_storage` by the controller.
     *
     * @return array what happened, for the message the user sees
     */
    public function deleteTakeout(SupplyTakeoutModel $takeout, int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($takeout, $userId, $reason) {
            /** @var SupplyTakeoutModel $fresh */
            $fresh = SupplyTakeoutModel::where('id', $takeout->id)->lockForUpdate()->firstOrFail();

            if (in_array($fresh->status, SupplyTakeoutModel::RESTORED_STATUSES, true)) {
                throw new \RuntimeException('That take-out was already reversed — the stock is back in Storage.');
            }

            $product = SupplyProductModel::find($fresh->product_id);
            $qtyBack = (float) $fresh->qty;
            $costBack = (float) $fresh->cost;
            $note = trim('Deleted from Storage' . ($reason ? ' — ' . $reason : ''));
            $monthTouched = null;

            $request = $fresh->request_id ? RequestModel::find($fresh->request_id) : null;

            if ($request) {
                // ⚠ The money comes back FIRST. restore() then puts the kilograms back, and
                // the two must move together or the stock account and the shelf disagree —
                // which is exactly the round-2 bug this feature already shipped once.
                if ($request->ledger_transaction_id) {
                    $ledger = LedgerModel::find($request->ledger_transaction_id);
                    if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                        (new BalancePostingService())->reverse($ledger);
                        $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                        $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '') . $note);
                        $ledger->save();
                        $monthTouched = substr((string) $ledger->transaction_date, 0, 7);
                    }
                }

                if ($request->status !== RequestModel::STATUS_CANCELLED) {
                    $request->setAttribute('status', RequestModel::STATUS_CANCELLED);
                    $request->rejection_reason = $note;
                    $request->updated_by = $userId;
                    $request->save();
                }
            }

            $this->restore($fresh, SupplyTakeoutModel::STATUS_UNDONE, $note, true);

            return [
                'qty' => $qtyBack,
                'cost' => $costBack,
                'unit' => $product ? $this->poolUnit($product) : $fresh->unit,
                'request_number' => $request?->request_number,
                'month_touched' => $monthTouched,
            ];
        });
    }

    /**
     * ⭐⭐ Change the WEIGHT of a take-out that was already recorded — an atomic
     * undo-and-redo, never a patch.
     *
     * Patching `qty` alone would be the worst kind of bug: the take-out would read right
     * while the purchases it came out of stayed drawn down by the old amount, for ever.
     * So the legs are given back, the new weight is drawn from the pool exactly as a fresh
     * scan would draw it (same FIFO, same sweep, same tolerance), and only then is the
     * money settled.
     *
     * The money reuses `recostTakeout()`, built for price corrections: it reverses the
     * expense, amends the amount and re-applies it ON ITS ORIGINAL DATE, so a correction
     * never silently moves a cost into a different month.
     */
    public function editTakeoutWeight(
        SupplyTakeoutModel $takeout,
        float $newQty,
        int $userId,
        ?string $reason = null
    ): array {
        return DB::transaction(function () use ($takeout, $newQty, $userId, $reason) {
            /** @var SupplyTakeoutModel $fresh */
            $fresh = SupplyTakeoutModel::where('id', $takeout->id)->lockForUpdate()->firstOrFail();

            if (in_array($fresh->status, SupplyTakeoutModel::RESTORED_STATUSES, true)) {
                throw new \RuntimeException('That take-out was already reversed — there is nothing to edit.');
            }

            $product = SupplyProductModel::findOrFail($fresh->product_id);
            if (!$product->isPooled()) {
                throw new \RuntimeException('Only a weighed or counted take-out can have its quantity edited.');
            }

            $newQty = round($newQty, 3);
            if ($newQty <= 0) {
                throw new \RuntimeException('Enter a quantity above zero — use Delete to reverse it entirely.');
            }

            $wasQty = (float) $fresh->qty;
            $wasCost = (float) $fresh->cost;

            // 1. give every leg back to the purchase it came from
            foreach (SupplyTakeoutLegModel::where('takeout_id', $fresh->id)->get() as $leg) {
                $batch = SupplyBatchModel::where('id', $leg->batch_id)->lockForUpdate()->first();
                if ($batch) {
                    $batch->qty_remaining = round((float) $batch->qty_remaining + (float) $leg->qty, 3);
                    $batch->cost_remaining = round((float) $batch->cost_remaining + (float) $leg->cost, 2);
                    $batch->save();
                }
                $leg->delete();
            }

            // 2. draw the new quantity exactly as a fresh scan would.
            //    If it does not fit, this throws and the whole transaction rolls back —
            //    the legs above are restored, nothing has moved.
            $legs = $this->consumeFromPool($product, $newQty, $this->toleranceFor($product));

            $qty = round(array_sum(array_column($legs, 'qty')), 3);
            $cost = round(array_sum(array_column($legs, 'cost')), 2);

            foreach ($legs as $leg) {
                SupplyTakeoutLegModel::create([
                    'takeout_id' => $fresh->id,
                    'batch_id' => $leg['batch_id'],
                    'packet_id' => null,
                    'qty' => $leg['qty'],
                    'cost' => $leg['cost'],
                    'created_at' => now(),
                ]);
            }

            $fresh->qty = $qty;
            $fresh->save();

            // 3. the money, by what the take-out already is
            $billable = $cost >= self::MIN_BILLABLE;
            $note = trim('Weight corrected ' . $this->trimNumber($wasQty) . ' → ' . $this->trimNumber($qty)
                . ' ' . $this->poolUnit($product) . ($reason ? ' — ' . $reason : ''));
            $monthTouched = null;
            $crossed = null;

            if ($fresh->status === SupplyTakeoutModel::STATUS_NO_CHARGE && $billable) {
                // ⚠ The edit crossed FREE → PAID: the new kilograms reach into a purchase
                // that was actually bought, so this take-out now owes an expense it never had.
                $fresh->cost = $cost;
                $fresh->status = SupplyTakeoutModel::STATUS_PENDING;
                $fresh->settled_at = null;
                $fresh->save();
                // ⭐ On the day it was TAKEN, under the person who TOOK it — the same rule as
                // every other correction here (recostTakeout re-applies on the original date).
                $this->raiseExpenseRequest(
                    $fresh->fresh(), $product, $legs, $userId,
                    optional($fresh->taken_at)->toDateString(),
                    $fresh->taken_by ? (int) $fresh->taken_by : null
                );
                $fresh = $fresh->fresh();
                $crossed = 'now_billable';
            } elseif ($fresh->status !== SupplyTakeoutModel::STATUS_NO_CHARGE && !$billable) {
                // ⚠ PAID → FREE: everything it now draws on is opening stock. The expense
                // has to come off, or we would bill for stock that was already expensed.
                $request = $fresh->request_id ? RequestModel::find($fresh->request_id) : null;
                if ($request) {
                    if ($request->ledger_transaction_id) {
                        $ledger = LedgerModel::find($request->ledger_transaction_id);
                        if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                            (new BalancePostingService())->reverse($ledger);
                            $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                            $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '') . $note);
                            $ledger->save();
                            $monthTouched = substr((string) $ledger->transaction_date, 0, 7);
                        }
                    }
                    $request->setAttribute('status', RequestModel::STATUS_CANCELLED);
                    $request->rejection_reason = $note;
                    $request->updated_by = $userId;
                    $request->save();
                }
                $fresh->cost = $cost;
                $fresh->status = SupplyTakeoutModel::STATUS_NO_CHARGE;
                $fresh->settled_at = now();
                $fresh->save();
                $crossed = 'now_free';
            } else {
                // The ordinary case: same side of the free/paid line, just a different figure.
                $moved = $this->recostTakeout($fresh->id, $cost, $userId, $note, 'Storage take-out weight corrected');
                $monthTouched = $moved['month'] ?? null;
                if (!$moved) {
                    $fresh->cost = $cost;
                    $fresh->save();
                }
                $fresh = $fresh->fresh();
            }

            // 4. the audit row — ACTION_CORRECT, the same action a price fix writes
            $this->log(SupplyLogModel::ACTION_CORRECT, $product, null, null, $fresh,
                $qty, $this->poolUnit($product), $cost, $fresh->source, $userId, $note,
                $fresh->scanned_barcode);

            return [
                'was_qty' => $wasQty,
                'qty' => $qty,
                'was_cost' => $wasCost,
                'cost' => $cost,
                'unit' => $this->poolUnit($product),
                'legs' => $legs,
                'status' => $fresh->status,
                'crossed' => $crossed,
                'month_touched' => $monthTouched,
            ];
        });
    }

    /**
     * What editTakeoutWeight() WOULD do. Writes nothing.
     *
     * Its figure must equal what the edit then produces to the paisa, so it prices against
     * the pool WITH this take-out's own kilograms handed back first — otherwise it would
     * quote against a shelf that is still missing the stock it is about to return.
     */
    public function previewTakeoutEdit(SupplyTakeoutModel $takeout, float $newQty): array
    {
        $product = SupplyProductModel::findOrFail($takeout->product_id);

        $addBack = [];
        foreach (SupplyTakeoutLegModel::where('takeout_id', $takeout->id)->get() as $leg) {
            $addBack[$leg->batch_id]['qty'] = ($addBack[$leg->batch_id]['qty'] ?? 0) + (float) $leg->qty;
            $addBack[$leg->batch_id]['cost'] = ($addBack[$leg->batch_id]['cost'] ?? 0) + (float) $leg->cost;
        }

        $preview = $this->previewPoolConsumption(
            $product, round($newQty, 3), $addBack, $this->toleranceFor($product)
        );

        $request = $takeout->request_id ? RequestModel::find($takeout->request_id) : null;
        $expenseMonth = $request && $request->expense_date ? substr((string) $request->expense_date, 0, 7) : null;

        return array_merge($preview, [
            'was_qty' => (float) $takeout->qty,
            'was_cost' => (float) $takeout->cost,
            'unit' => $this->poolUnit($product),
            'expense_month' => $expenseMonth,
            // A month already read by the owner must never be restated behind his back.
            'restates_earlier_month' => $expenseMonth !== null && $expenseMonth < now()->format('Y-m'),
        ]);
    }

    /**
     * The median weight of this product's recent take-outs — the baseline the "unusual
     * weight" guard compares a new one against.
     *
     * A median, not a mean, for the same reason intake uses one: a single wild reading
     * drags a mean far enough to then accept the NEXT bad one.
     */
    public function recentTakeoutMedian(SupplyProductModel $product, int $sample = 10): float
    {
        $qtys = SupplyTakeoutModel::where('product_id', $product->id)
            ->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES)
            ->orderByDesc('id')->limit($sample)->pluck('qty')
            ->map(fn ($q) => (float) $q)->filter(fn ($q) => $q > 0)->values()->sort()->values()->all();

        $n = count($qtys);
        if ($n === 0) {
            return 0.0;   // no baseline yet — the first take-outs are never questioned
        }

        $mid = intdiv($n, 2);

        return $n % 2 ? $qtys[$mid] : ($qtys[$mid - 1] + $qtys[$mid]) / 2;
    }

    /** The same label, out of the same product, within the last few minutes. */
    public function sameLabelRecently(SupplyProductModel $product, ?string $barcode, int $minutes = 10): ?SupplyTakeoutModel
    {
        if (!$barcode) {
            return null;
        }

        return SupplyTakeoutModel::where('product_id', $product->id)
            ->where('scanned_barcode', $barcode)
            ->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES)
            ->where('taken_at', '>=', now()->subMinutes($minutes))
            ->orderByDesc('id')->first();
    }

    /**
     * ⭐ Correct the price of a batch that was entered wrong — even after some of it has
     * been used. (Voiding only works while a batch is untouched, and a typo is usually
     * spotted after a packet or two has gone out.)
     *
     * WHAT MOVES, and why:
     *  1. The PURCHASE row is amended in place at its ORIGINAL date. The cash really did
     *     leave that day — only the amount was wrong — so the bank/cash account is
     *     corrected by the difference on the day it actually happened.
     *  2. Every packet is RE-COSTED at the new total, by weight, last-takes-remainder.
     *  3. A packet already taken out and APPROVED had an expense posted at the old share.
     *     That row is reversed, amended and re-applied on its ORIGINAL expense date — the
     *     packet was genuinely used that day, only the rupee figure was wrong. Correcting
     *     it in place is what keeps each month's packaging cost true; posting the whole
     *     difference into today's month instead would misstate BOTH months.
     *  4. A take-out still PENDING has no ledger yet — only its request amount changes.
     *
     * ⚠ This DOES move a figure in a month that may already have been read. It is the
     *   honest answer (the month's real packaging cost was always the corrected one), but
     *   the caller must tell the user which months are affected before they confirm —
     *   see previewPriceCorrection().
     */
    public function correctBatchPrice(SupplyBatchModel $batch, float $newTotal, int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($batch, $newTotal, $userId, $reason) {
            /** @var SupplyBatchModel $b */
            $b = SupplyBatchModel::where('id', $batch->id)->lockForUpdate()->first();
            if (!$b) {
                throw new \RuntimeException('That purchase no longer exists.');
            }
            if ($b->status !== SupplyBatchModel::STATUS_CONFIRMED) {
                throw new \RuntimeException($b->status === SupplyBatchModel::STATUS_STOCK_ONLY
                    ? 'This was booked as opening stock, so it has no price to correct.'
                    : 'This purchase was voided — nothing to correct.');
            }
            $newTotal = round($newTotal, 2);
            if ($newTotal <= 0) {
                throw new \RuntimeException('The corrected amount must be more than zero.');
            }

            $product = SupplyProductModel::find($b->product_id);
            $oldTotal = (float) $b->total_cost;
            $changed = [];

            // ── 1. the purchase row, amended in place at its original date ──────────
            if ($b->ledger_id) {
                $ledger = LedgerModel::find($b->ledger_id);
                if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                    // The documented caller pattern for editing an applied row.
                    $poster = new BalancePostingService();
                    $poster->reverse($ledger);
                    $ledger->amount = $newTotal;
                    $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                        . 'Price corrected ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)
                        . ' by user ' . $userId . ' on ' . now()->toDateTimeString()
                        . ($reason ? ' — ' . $reason : ''));
                    $ledger->save();
                    $poster->apply($ledger);
                }
            }

            // ── 2. re-cost every packet / leg at the new total ──────────────────────
            if ($product && $product->usesPackets()) {
                $packets = SupplyPacketModel::where('batch_id', $b->id)
                    ->where('status', '!=', SupplyPacketModel::STATUS_VOIDED)
                    ->orderBy('seq')->lockForUpdate()->get();

                // ⭐ The SAME allocation the preview showed — so the figure the manager
                //    confirmed is the figure that lands, to the paisa.
                $newCosts = $this->allocateByWeight($packets, $newTotal);

                foreach ($packets as $pk) {
                    $cost = $newCosts[$pk->id];
                    $wasCost = (float) $pk->cost;
                    $pk->cost = $cost;
                    $pk->save();

                    SupplyTakeoutLegModel::where('packet_id', $pk->id)->update(['cost' => $cost]);

                    if ($pk->status === SupplyPacketModel::STATUS_CONSUMED && abs($wasCost - $cost) >= 0.005) {
                        $moved = $this->recostTakeout($pk->takeout_id, $cost, $userId, $reason);
                        if ($moved) { $changed[] = $moved; }
                    }
                }
                $b->cost_remaining = round($packets->where('status', SupplyPacketModel::STATUS_IN_STOCK)
                    ->sum(fn ($p) => (float) $p->cost), 2);
            } else {
                // POOLED (weight or pieces): re-cost each leg at the new rate; the shelf
                // absorbs the rounding (or, once used up, the finishing leg does).
                $legTotal = 0.0;

                // ⭐ A weighed purchase also has packet rows — intake audit, not stock. They
                //    carry no money, but leaving them at the OLD price would make the "what
                //    was weighed in" record disagree with what the purchase actually cost.
                if ($product && $product->booksPackets()) {
                    $auditRows = SupplyPacketModel::where('batch_id', $b->id)
                        ->where('status', '!=', SupplyPacketModel::STATUS_VOIDED)
                        ->orderBy('seq')->get();
                    if ($auditRows->isNotEmpty()) {
                        $auditCosts = $this->allocateByWeight($auditRows, $newTotal);
                        foreach ($auditRows as $row) {
                            $row->cost = $auditCosts[$row->id];
                            $row->save();
                        }
                    }
                }
                $legs = SupplyTakeoutLegModel::where('batch_id', $b->id)->orderBy('id')->lockForUpdate()->get();

                // ⚠ Sep-26 review: a USED-UP purchase must land on exactly zero — see
                // pooledCorrectionCosts(), which the preview uses too.
                $newCosts = $this->pooledCorrectionCosts($b, $legs, $newTotal);

                foreach ($legs as $leg) {
                    if (!array_key_exists($leg->id, $newCosts)) {
                        continue;   // a rejected / undone take-out gave its quantity back
                    }
                    $wasCost = (float) $leg->cost;
                    $cost = $newCosts[$leg->id];
                    $leg->cost = $cost;
                    $leg->save();
                    $legTotal += $cost;

                    if (abs($wasCost - $cost) >= 0.005) {
                        $sum = round(SupplyTakeoutLegModel::where('takeout_id', $leg->takeout_id)->sum('cost'), 2);
                        $moved = $this->recostTakeout($leg->takeout_id, $sum, $userId, $reason);
                        if ($moved) { $changed[] = $moved; }
                    }
                }
                $b->cost_remaining = round($newTotal - $legTotal, 2);
            }

            // ── 3. the batch itself ─────────────────────────────────────────────────
            $b->total_cost = $newTotal;
            $b->unit_cost = (float) $b->qty_total > 0 ? round($newTotal / (float) $b->qty_total, 4) : null;
            $b->save();

            $this->log(SupplyLogModel::ACTION_CORRECT, $product, $b, null, null,
                (float) $b->qty_total, $product ? $product->takeoutUnit() : 'kg', $newTotal, null, $userId,
                'Price corrected ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2)
                . ($reason ? ' — ' . $reason : ''));

            return ['old_total' => $oldTotal, 'new_total' => $newTotal, 'expenses_changed' => $changed];
        });
    }

    /**
     * Split a total across packets by weight, LAST packet takes the remainder so the
     * shares sum to the total exactly. The ONE allocation rule — used by the intake, the
     * correction and the correction PREVIEW, so a previewed figure is always the one
     * that lands. Returns [packet_id => cost] in seq order.
     */
    private function allocateByWeight($packets, float $total): array
    {
        $qtyTotal = round($packets->sum(fn ($p) => (float) $p->qty), 3);
        $out = [];
        $allocated = 0.0;
        $last = $packets->count() - 1;

        foreach ($packets->values() as $i => $pk) {
            if ($i === $last) {
                $cost = round($total - $allocated, 2);
            } else {
                $cost = $qtyTotal > 0 ? round($total * (float) $pk->qty / $qtyTotal, 2) : 0.0;
                $allocated += $cost;
            }
            $out[$pk->id] = $cost;
        }

        return $out;
    }

    /**
     * A pooled purchase's legs at a corrected price: each at `new rate × qty`, EXCEPT that
     * when the purchase is used up, the leg that finished it takes the remainder — so the
     * purchase lands on exactly zero instead of stranding a few paisa with no quantity left
     * to carry them (Sep-26 review). Restored take-outs are left out. The correction and its
     * preview both use this, so the previewed figure is the one that lands.
     *
     * @return array [leg_id => cost]
     */
    private function pooledCorrectionCosts(SupplyBatchModel $b, $legs, float $newTotal): array
    {
        $unit = $newTotal / max((float) $b->qty_total, 0.0001);
        $active = $legs->filter(function ($leg) {
            $t = SupplyTakeoutModel::find($leg->takeout_id);
            return !($t && in_array($t->status, SupplyTakeoutModel::RESTORED_STATUSES, true));
        })->sortBy('id')->values();

        $lastId = ((float) $b->qty_remaining < 0.0005 && $active->isNotEmpty()) ? $active->last()->id : null;
        $out = [];
        $sum = 0.0;
        foreach ($active as $leg) {
            $cost = $leg->id === $lastId
                ? round($newTotal - $sum, 2)
                : round($unit * (float) $leg->qty, 2);
            $sum += $cost;
            $out[$leg->id] = $cost;
        }

        return $out;
    }

    /**
     * Re-cost ONE take-out after its packet's share changed. A pending one has no ledger
     * yet, so only the request amount moves; an approved one has its expense reversed,
     * amended and re-applied on its ORIGINAL date.
     *
     * @return array|null what moved, for the confirmation message
     */
    private function recostTakeout(
        ?int $takeoutId,
        float $newCost,
        int $userId,
        ?string $reason,
        string $what = 'Storage purchase price corrected'
    ): ?array {
        if (!$takeoutId) {
            return null;
        }
        $takeout = SupplyTakeoutModel::find($takeoutId);
        if (!$takeout || in_array($takeout->status, SupplyTakeoutModel::RESTORED_STATUSES, true)) {
            return null;
        }

        $was = (float) $takeout->cost;
        $takeout->cost = $newCost;
        $takeout->save();

        $request = $takeout->request_id ? RequestModel::find($takeout->request_id) : null;

        // ⚠ Sep-26 review: a FREE take-out (it cost under a paisa, so it raised no expense)
        // whose share of a purchase goes UP after a price correction now owes one. Without
        // this its cost changed but no expense ever existed, and that share sat in the stock
        // account for ever. Raised on the day it was taken, under the person who took it.
        if (!$request && $takeout->status === SupplyTakeoutModel::STATUS_NO_CHARGE
            && $newCost >= self::MIN_BILLABLE) {
            $product = SupplyProductModel::find($takeout->product_id);
            $legs = SupplyTakeoutLegModel::where('takeout_id', $takeout->id)->orderBy('id')->get()
                ->map(fn ($l) => ['batch_id' => $l->batch_id, 'packet_id' => $l->packet_id,
                                  'qty' => (float) $l->qty, 'cost' => (float) $l->cost])
                ->all();
            if ($product && $legs) {
                $takeout->status = SupplyTakeoutModel::STATUS_PENDING;
                $takeout->settled_at = null;
                $takeout->save();
                $this->raiseExpenseRequest(
                    $takeout->fresh(), $product, $legs, $userId,
                    optional($takeout->taken_at)->toDateString(),
                    $takeout->taken_by ? (int) $takeout->taken_by : null
                );
                $request = RequestModel::find($takeout->fresh()->request_id);
                return [
                    'request_number' => $request?->request_number,
                    'was' => $was,
                    'now' => $newCost,
                    'month' => $request && $request->expense_date ? substr((string) $request->expense_date, 0, 7) : null,
                ];
            }
        }

        if (!$request) {
            return null;
        }
        $request->amount = $newCost;
        $request->save();

        $month = null;
        if ($request->ledger_transaction_id) {
            $ledger = LedgerModel::find($request->ledger_transaction_id);
            if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                $poster = new BalancePostingService();
                $poster->reverse($ledger);
                $ledger->amount = $newCost;
                $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                    . 'Amount corrected ' . number_format($was, 2) . ' → ' . number_format($newCost, 2)
                    . ' (' . $what . ')' . ($reason ? ' — ' . $reason : ''));
                $ledger->save();
                $poster->apply($ledger);
                $month = substr((string) $ledger->transaction_date, 0, 7);
            }
        }

        return [
            'request_number' => $request->request_number,
            'was' => $was,
            'now' => $newCost,
            'month' => $month,
        ];
    }

    /**
     * What WOULD change if the price were corrected — shown before anything moves, so a
     * month that has already been read is never quietly restated.
     */
    public function previewPriceCorrection(SupplyBatchModel $batch, float $newTotal): array
    {
        $newTotal = round($newTotal, 2);
        $product = SupplyProductModel::find($batch->product_id);
        $old = (float) $batch->total_cost;
        $ratio = $old > 0 ? $newTotal / $old : 0;

        $rows = [];
        $months = [];
        $takeouts = SupplyTakeoutModel::whereIn('id',
            SupplyTakeoutLegModel::where('batch_id', $batch->id)->pluck('takeout_id'))
            ->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES)
            ->with('request')->get();

        // Packet products: use the EXACT allocation the correction will apply (last takes
        // the remainder), not a ratio — a ratio was one paisa off on the remainder packet,
        // so the figure previewed was not the figure that landed.
        $newByPacket = null;
        if ($product && $product->usesPackets()) {
            $packets = SupplyPacketModel::where('batch_id', $batch->id)
                ->where('status', '!=', SupplyPacketModel::STATUS_VOIDED)->orderBy('seq')->get();
            $newByPacket = $this->allocateByWeight($packets, $newTotal);
        }

        // ⭐ A POOLED purchase re-costs each leg at the NEW RATE — `unit × kg`, which is
        //    exactly what correctBatchPrice() then applies. Previewing with a ratio of the
        //    old cost instead would drift a paisa off the figure that actually lands, and
        //    the whole point of the preview is that what it shows is what happens.
        $poolUnit = ($product && $product->isPooled() && (float) $batch->qty_total > 0)
            ? $newTotal / (float) $batch->qty_total
            : null;

        $poolCosts = $poolUnit !== null
            ? $this->pooledCorrectionCosts($batch, SupplyTakeoutLegModel::where('batch_id', $batch->id)->get(), $newTotal)
            : [];

        foreach ($takeouts as $t) {
            $legs = SupplyTakeoutLegModel::where('takeout_id', $t->id)->where('batch_id', $batch->id)->get();
            $legCost = (float) $legs->sum('cost');
            if ($newByPacket !== null) {
                $newLegCost = (float) $legs->sum(fn ($l) => $newByPacket[$l->packet_id] ?? (float) $l->cost);
            } elseif ($poolUnit !== null) {
                $newLegCost = (float) $legs->sum(fn ($l) => $poolCosts[$l->id] ?? round($poolUnit * (float) $l->qty, 2));
            } else {
                $newLegCost = round($legCost * $ratio, 2);
            }
            $now = round((float) $t->cost - $legCost + $newLegCost, 2);
            if (abs($now - (float) $t->cost) < 0.005) {
                continue;
            }
            $m = $t->request && $t->request->expense_date
                ? substr((string) $t->request->expense_date, 0, 7) : null;
            if ($m) { $months[$m] = true; }
            $rows[] = [
                'request_number' => $t->request?->request_number,
                'status' => $t->status,
                'was' => (float) $t->cost,
                'now' => $now,
                'month' => $m,
            ];
        }

        return [
            'old_total' => $old,
            'new_total' => $newTotal,
            'difference' => round($newTotal - $old, 2),
            'paid_from' => AccountModel::find($batch->payment_source_account_id)?->account_name,
            'purchase_month' => substr((string) $batch->purchase_date, 0, 7),
            'expenses' => $rows,
            'months_affected' => array_keys($months),
            'unit_label' => $product ? $product->takeoutUnit() : '',
        ];
    }

    /**
     * Reverse a whole batch booked in error. Only while NOTHING has been consumed —
     * once a packet is out, the money is partly in an approved expense and unwinding
     * it silently would leave the two disagreeing.
     */
    public function voidBatch(SupplyBatchModel $batch, int $userId): void
    {
        DB::transaction(function () use ($batch, $userId) {
            $fresh = SupplyBatchModel::where('id', $batch->id)->lockForUpdate()->first();
            if (!$fresh || $fresh->status === SupplyBatchModel::STATUS_VOIDED) {
                return;
            }

            // ⚠ ASK THE LEGS, not the packet rows. A weighed purchase is drawn down as a
            // pool now, so its packet rows stay `in_stock` forever while kilograms leave —
            // the old packet check would have happily voided a bale that was half used and
            // reversed money that had already been expensed.
            $legCount = SupplyTakeoutLegModel::where('batch_id', $fresh->id)
                ->whereHas('takeout', fn ($q) => $q->whereNotIn('status', SupplyTakeoutModel::RESTORED_STATUSES))
                ->count();
            $consumed = SupplyPacketModel::where('batch_id', $fresh->id)
                ->where('status', SupplyPacketModel::STATUS_CONSUMED)->count();
            $qtyUsed = (float) $fresh->qty_remaining < (float) $fresh->qty_total - 0.0005;

            if ($legCount > 0 || $consumed > 0 || $qtyUsed) {
                throw new \RuntimeException(
                    $legCount > 0
                        ? $legCount . ' take-out(s) have already come out of this purchase. Delete those first, '
                            . 'or use Fix price if only the amount was wrong.'
                        : 'Some of this purchase has already been used — it can no longer be voided.'
                );
            }

            if ($fresh->ledger_id) {
                $ledger = LedgerModel::find($fresh->ledger_id);
                if ($ledger && $ledger->approval_status === LedgerModel::STATUS_APPROVED) {
                    (new BalancePostingService())->reverse($ledger);
                    $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                    $ledger->comments = trim(($ledger->comments ? $ledger->comments . "\n" : '')
                        . 'Batch voided by user ' . $userId . ' on ' . now()->toDateTimeString());
                    $ledger->save();
                }
            }

            SupplyPacketModel::where('batch_id', $fresh->id)
                ->update(['status' => SupplyPacketModel::STATUS_VOIDED]);

            $fresh->status = SupplyBatchModel::STATUS_VOIDED;
            $fresh->qty_remaining = 0;
            $fresh->cost_remaining = 0;
            $fresh->voided_by = $userId;
            $fresh->voided_at = now();
            $fresh->save();

            // ⚠ The unit is the PRODUCT's, not a hardcoded 'kg' — a voided batch of cups
            // used to be logged as kilograms in the History tab.
            $voidProduct = SupplyProductModel::find($fresh->product_id);
            $this->log(SupplyLogModel::ACTION_VOID, $voidProduct,
                $fresh, null, null, (float) $fresh->qty_total,
                $voidProduct ? $voidProduct->takeoutUnit() : 'kg', (float) $fresh->total_cost,
                null, $userId, 'Batch voided');
        });
    }

    // -----------------------------------------------------------------------------
    // STOCK COUNT
    // -----------------------------------------------------------------------------

    /**
     * ⭐ A physical shelf count — the only thing that can catch a packet taken WITHOUT
     * scanning it out.
     *
     * Nothing else detects that: the stock figure stays high and the expense never lands,
     * so the shelf and the books drift apart quietly and permanently. Counting compares
     * what is physically there against what the system believes.
     *
     * A SHORTFALL means those packets were used — someone just didn't scan them — so
     * `write_off` expenses them exactly like a normal take-out, attributed to whoever
     * counted, with the reason on the record. Refusing to write off would leave stock
     * permanently overstated, which is the worse of the two errors.
     *
     * A SURPLUS is never written up: stock cannot appear without a price, so the count is
     * recorded and the manager is told to book the purchase that was missed.
     */
    public function recordCount(SupplyProductModel $product, float $counted, bool $writeOff, int $userId, ?string $note = null): array
    {
        return DB::transaction(function () use ($product, $counted, $writeOff, $userId, $note) {
            $expected = $this->onHand($product);
            $counted = $product->usesPackets() ? round($counted) : round($counted, 3);
            $gap = round($counted - $expected, 3);

            $writtenOff = [];
            $cost = 0.0;

            if ($gap < 0 && $writeOff) {
                $short = abs($gap);
                $reason = 'Missing at stock count' . ($note ? ' — ' . $note : '');

                if ($product->usesPackets()) {
                    // Oldest first, exactly like a scan, so the cost is what was paid.
                    $packets = $this->availablePackets($product)->take((int) $short);
                    foreach ($packets as $pk) {
                        $t = $this->takeOut([
                            'product_id' => $product->id, 'packet_id' => $pk->id,
                            'source' => 'manual', 'note' => $reason,
                        ], $userId);
                        $writtenOff[] = ['takeout_id' => $t->id, 'qty' => (float) $t->qty, 'cost' => (float) $t->cost];
                        $cost += (float) $t->cost;
                    }
                } else {
                    $t = $this->takeOut([
                        'product_id' => $product->id, 'qty' => $short,
                        'source' => 'manual', 'note' => $reason,
                    ], $userId);
                    $writtenOff[] = ['takeout_id' => $t->id, 'qty' => (float) $t->qty, 'cost' => (float) $t->cost];
                    $cost += (float) $t->cost;
                }
            }

            // ⚠ A count is in the unit you COUNT IN, which is not always the unit a take-out
            // is measured in: a SCAN product is counted in packets while its take-outs read
            // "1 packet", and a weighed shelf is now WEIGHED (round 3) rather than counted in
            // bags. onHandPhrase() is the counting unit; quantityPhrase() is the take-out
            // unit. Conflating them once wrote "Counted 3 kg" when someone had counted 3 bags.
            $this->log(SupplyLogModel::ACTION_COUNT, $product, null, null, null,
                $counted, $this->onHandUnit($product), $cost, 'manual', $userId,
                trim('Counted ' . $this->onHandPhrase($counted, $product)
                    . ', system had ' . $this->onHandPhrase($expected, $product)
                    . ($gap == 0 ? ' — matched' : ($gap < 0 ? ' — SHORT by ' . $this->onHandPhrase(abs($gap), $product)
                                                            : ' — OVER by ' . $this->onHandPhrase($gap, $product)))
                    . ($gap < 0 && $writeOff ? ' — written off' : '')
                    . ($note ? '. ' . $note : '')));

            return [
                'expected' => $expected,
                'counted' => $counted,
                'gap' => $gap,
                'written_off' => $writtenOff,
                'written_off_cost' => round($cost, 2),
            ];
        });
    }

    /** What the system believes is on the shelf right now. */
    public function onHand(SupplyProductModel $product): float
    {
        if ($product->usesPackets()) {
            return (float) SupplyPacketModel::where('product_id', $product->id)
                ->where('status', SupplyPacketModel::STATUS_IN_STOCK)->count();
        }

        return round((float) SupplyBatchModel::where('product_id', $product->id)
            ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->sum('qty_remaining'), 3);
    }

    /** The most recent count per product, for the "last counted" line. */
    public function lastCount(int $productId): ?SupplyLogModel
    {
        return SupplyLogModel::where('product_id', $productId)
            ->where('action', SupplyLogModel::ACTION_COUNT)
            ->orderByDesc('id')->first();
    }

    // -----------------------------------------------------------------------------
    // READS
    // -----------------------------------------------------------------------------

    /** In-stock packets of a product, oldest batch first — the take-out picker list. */
    public function availablePackets(SupplyProductModel $product)
    {
        return SupplyPacketModel::query()
            ->select('t_fin_supply_packet.*')
            ->join('t_fin_supply_batch as b', 'b.id', '=', 't_fin_supply_packet.batch_id')
            ->where('t_fin_supply_packet.product_id', $product->id)
            ->where('t_fin_supply_packet.status', SupplyPacketModel::STATUS_IN_STOCK)
            ->where('b.status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->orderBy('b.purchase_date')
            ->orderBy('b.id')
            ->orderBy('t_fin_supply_packet.seq')
            ->get();
    }

    /**
     * The packet a scanned label refers to: exact barcode, oldest batch first.
     * Two identical bags print the same label, so this is a FIFO pick, not a lookup —
     * and because their costs come from the same batch order, the choice is also the
     * financially correct one.
     */
    public function findPacketForBarcode(SupplyProductModel $product, string $raw): ?SupplyPacketModel
    {
        return SupplyPacketModel::query()
            ->select('t_fin_supply_packet.*')
            ->join('t_fin_supply_batch as b', 'b.id', '=', 't_fin_supply_packet.batch_id')
            ->where('t_fin_supply_packet.product_id', $product->id)
            ->where('t_fin_supply_packet.status', SupplyPacketModel::STATUS_IN_STOCK)
            ->where('t_fin_supply_packet.barcode', $raw)
            ->where('b.status', '!=', SupplyBatchModel::STATUS_VOIDED)
            ->orderBy('b.purchase_date')
            ->orderBy('b.id')
            ->orderBy('t_fin_supply_packet.seq')
            ->first();
    }

    /** Per-product stock: packets, quantity and the rupees still sitting on the shelf. */
    public function stockSummary(?int $businessUnitId = null): array
    {
        $products = SupplyProductModel::where('is_active', 1)
            ->when($businessUnitId, fn ($q) => $q->where('business_unit_id', $businessUnitId))
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            $batches = SupplyBatchModel::where('product_id', $product->id)
                ->where('status', '!=', SupplyBatchModel::STATUS_VOIDED)
                ->get();

            $qty = round($batches->sum(fn ($b) => (float) $b->qty_remaining), 3);
            $value = round($batches->sum(fn ($b) => (float) $b->cost_remaining), 2);
            $packets = $product->usesPackets()
                ? SupplyPacketModel::where('product_id', $product->id)
                    ->where('status', SupplyPacketModel::STATUS_IN_STOCK)->count()
                : null;

            $rows[] = [
                'product' => $product,
                'qty_remaining' => $qty,
                'value_remaining' => $value,
                'packets_in_stock' => $packets,
                // ⭐ How many purchases the pool is currently made of. The owner keeps
                // buying before it runs out, so "one line, tap to see what it is made of"
                // needs this number on the card itself.
                'open_purchases' => $batches->filter(fn ($b) => (float) $b->qty_remaining > 0.0005)->count(),
                'low_stock' => $product->low_stock_qty !== null
                    && $qty <= (float) $product->low_stock_qty,
            ];
        }

        return $rows;
    }

    /** Does a take-out queue for approval? Owner-facing switch, default ON. */
    public function takeoutsNeedApproval(): bool
    {
        return (string) ConfigModel::get(self::APPROVAL_SWITCH_KEY, '1') !== '0';
    }

    public function stockAccount(): ?AccountModel
    {
        return AccountModel::where('account_code', self::STOCK_ACCOUNT_CODE)->first();
    }

    // -----------------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------------

    /** "1.25 kg" / "4 packets" / "30 pcs" — the phrase a store user recognises. */
    public function quantityPhrase(float $qty, SupplyProductModel $product): string
    {
        if ($product->mode === SupplyProductModel::MODE_WEIGHT) {
            return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' kg';
        }

        $n = (int) round($qty);
        if ($product->mode === SupplyProductModel::MODE_SCAN) {
            return $n . ' packet' . ($n === 1 ? '' : 's');
        }

        return $n . ' pcs';
    }

    /**
     * The unit a SHELF COUNT is expressed in — what onHand() actually returns.
     *
     * ⚠ Not the same as takeoutUnit(). A weight product is taken out in KILOGRAMS but
     * counted in PACKETS (you count bags on a shelf, you do not weigh the shelf), and
     * conflating the two put "3 kg" in the log when someone counted 3 bags.
     */
    public function onHandUnit(SupplyProductModel $product): string
    {
        return match ($product->mode) {
            // ⭐ Round 3: a weighed shelf is COUNTED IN KG. You weigh what is left, you do
            //    not count bags — the bags on the shelf are no longer what the stock is.
            SupplyProductModel::MODE_WEIGHT => 'kg',
            SupplyProductModel::MODE_SCAN   => 'packet',
            default                         => 'pcs',
        };
    }

    /** "3 packets" / "30 pcs" — a counted quantity, in the unit it was counted in. */
    public function onHandPhrase(float $qty, SupplyProductModel $product): string
    {
        if ($product->mode === SupplyProductModel::MODE_WEIGHT) {
            return $this->trimNumber($qty) . ' kg';
        }
        if (!$product->usesPackets()) {
            return ((int) round($qty)) . ' pcs';
        }

        $n = (int) round($qty);

        return $n . ' packet' . ($n === 1 ? '' : 's');
    }

    /**
     * ⭐ The description is where the ORIGINAL paying account lives.
     *
     * Every screen that shows an expense prints a description — Expenses, the Ledger,
     * the Reports drill, HQ, the approval card — so putting the parent account here
     * makes it readable everywhere without changing a single one of those screens.
     * Their "Paid from" column keeps saying Storage stock, which is the truth of THIS
     * leg: no cash moves when a packet is used.
     */
    private function takeoutDescription(SupplyProductModel $product, string $qtyPhrase, array $legs): string
    {
        $parts = [];
        foreach ($legs as $leg) {
            $batch = SupplyBatchModel::find($leg['batch_id']);
            if (!$batch) {
                continue;
            }
            $parts[] = 'batch #' . $batch->id
                . ' (' . optional($batch->purchase_date)->format('d-M') . ') · '
                . $batch->parentAccountPhrase();
        }

        return 'Storage take-out: ' . $product->name . ' ' . $qtyPhrase
            . ' — charged to ' . $product->expense_category_name
            . '. ' . implode(' + ', $parts)
            . '. The money left that account when the stock was bought; this only books the cost to the month it was used.';
    }

    private function log(
        string $action,
        ?SupplyProductModel $product,
        ?SupplyBatchModel $batch,
        ?SupplyPacketModel $packet,
        ?SupplyTakeoutModel $takeout,
        float $qty,
        string $unit,
        float $cost,
        ?string $source,
        ?int $userId,
        ?string $note = null,
        ?string $barcode = null
    ): void {
        SupplyLogModel::create([
            'action' => $action,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'batch_id' => $batch?->id,
            'packet_id' => $packet?->id,
            'takeout_id' => $takeout?->id,
            'qty' => $qty,
            'unit' => $unit,
            'cost' => $cost,
            'source' => $source,
            // The code read for this movement, in or out. Null for a typed quantity.
            'barcode' => $barcode,
            'note' => $note,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
