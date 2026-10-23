# HOTFIX — shop order cash → online crash (KS-36) — 19-Oct-2026 ✗live

## The bug
A delivered order for a **shop** customer (Khaas Warehouse, any shop) changed from cash to
**Online** in the web edit form:
1. the order row is saved as Online,
2. the cash invoice is reversed,
3. `postInvoiceFromOrder()` posts nothing (shop online orders settle in the Shop tab — by design),
4. the code then read `$result['ledger_id']` → **500 "Undefined array key ledger_id"** → step 2 rolled
   back, step 1 not. The order says Online, the cash invoice stays on the rider's Daily Closing, and
   the order is kept OUT of the Shop tab.

## Step 1 — unblock KS-36 NOW (SQL only, no upload)
`PROD-FIX-KS36-CASH-TO-ONLINE-OCT19-2026.sql` — pre-checks → one transaction → after-checks.
Safe to run before or after the upload below.

## Step 2 — upload the hotfix (2 files, no /xclean, no APK)
| Upload this | To |
|---|---|
| `HOTFIX-KS36-OCT19-2026/app/Http/Controllers/CRM/OrderController.php` | `app/Http/Controllers/CRM/OrderController.php` |
| `HOTFIX-KS36-OCT19-2026/app/Http/Controllers/CRM/WarehouseController.php` | `app/Http/Controllers/CRM/WarehouseController.php` |

⚠ Upload the files from the **`HOTFIX-KS36-OCT19-2026/` folder**, NOT `app/…` from the working tree.
Each hotfix copy = the committed (= live) file + only this round's lines (OrderController +43,
WarehouseController +6). The working-tree copies also carry rounds that are NOT live yet
(OrderController: Split invoice, Box count / original_quantity; WarehouseController: Bundles-out,
Recipe families…), which need their own SQL first.

Controller-only change ⇒ no `/xclean` needed. Either file can go first.

The working-tree files already contain the same changes, so when those rounds ship later the fix
ships with them — nothing to merge.

## Step 3 — optional: KS orders already in the pipeline
`PROD-KS-OPEN-ORDERS-ONLINE-OCT19-2026.sql` — any KS order placed BEFORE the upload that is still
undelivered and has no payment method → `online_payment`. Run after the upload (S1 shows what it
would touch; no rows = nothing to do).

## What changed (`OrderController.php`)
- `handlePaymentMethodChange()`: posting returned success with **no ledger id** (shop online /
  Qurbani skip) ⇒ commit the reversal and finish; `new_ledger_id = null`. "Already posted" still
  rolls back.
- `update()` success message for that case: "Cash invoice reversed — this shop order is now settled
  from Online Approvals → Shop tab."
- `update()` when the change **fails** for any reason: the order's payment method is put back to the
  old value, so the order and its invoice always agree and a retry is still seen as a change.

## What changed (`WarehouseController.php`) — owner ruling 19-Oct: Khaas orders are ONLINE
- `placeStorageOrder()`: new KS orders carry `payment_method = 'online_payment'` (the spelling the
  rider/store apps write; KS-17…35 all ended up with it). `convertOrder()` copies it to the live order.
  Khaas orders always go to customer 6419 Khaas Warehouse (shop), so delivery posts NO cash invoice
  and the order lands in Online Approvals → Shop tab. Side effect: no more hand-switch to online
  while out for delivery ⇒ the "payment changed → bank details" WhatsApp automation can no longer
  fire at Khaas Warehouse.

## After upload — check
- Pick nothing on prod to test with; the next real shop cash→online change will show the new message.
- Dev proof (rolled back, owner runs): `C:\xampp\php\php.exe "C:\NF App\PROOF-SHOP-CASH-TO-ONLINE-OCT19-2026.php"`
  — cases A (shop), B (regular control), C (forced failure restores the method).

## Not in this hotfix (flagged)
- `OrderRiderController::handleRiderChangeLedgerUpdate()` has the same `$result['ledger_id']` read.
  It only bites if a rider is changed on a delivered order whose invoice exists but whose re-post is
  skipped (shop + online) — rare, left alone.
- A rider/store person can still switch a KS order to CASH before delivery on purpose; that stays
  allowed (then it books as cash on the rider, like any shop cash order).
