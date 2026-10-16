# Deploy — Online invoice edit guards (14-Oct-2026)

Why: SH-23614 (Rs 53,687) vanished from Online Approvals on 14-Oct. Two causes, both now guarded.
Prod data was already repaired by SQL the same day (ledger 24473 back to pending_l1 + order relinked).

## Files (3 web, NO SQL, NO APK)

| # | File | Change |
|---|------|--------|
| 1 | `app/Http/Controllers/CRM/OrderController.php` | A plain edit save no longer reverses + re-posts the invoice. The edit form's dropdown posts `online` for a stored `online_payment` (and `cash_on_delivery` for `cash`); `update()` now keeps the stored spelling when the submitted method is the same family or blank (`paymentMethodFamily()`). A real cash↔online switch still reverses + re-posts. Reversal comment now names the real old method. |
| 2 | `app/Http/Controllers/CRM/CustomerController.php` | Saving a customer as **Shop** deletes their pending online invoices. If that would delete anything, the save is refused once with the exact list (HTTP 409, `needs_confirmation`), until the form returns `confirm_shop_conversion=1`. A flip that deletes nothing needs no confirmation. Shared query `pendingOnlineInvoiceQuery()` for the message and the deletion. |
| 3 | `resources/views/pages/customers/index.blade.php` | `saveCustomer()` shows the server's message in a confirm box and resends with the flag on Yes. |

## Steps
1. Upload the 3 files (WinSCP).
2. `/api/public/xclean` — required, file 3 is a Blade view.
3. Check: Customers → edit a customer who has a pending online invoice → set type Shop → Save → a confirm box lists the invoice(s); Cancel leaves everything as is.
4. Check: Orders → edit an online order (phone or address only) → Save → its row on Online Approvals keeps the same ledger id (no new "Payment method changed" row in `t_fin_ledger`).

## Proof
- OrderController: 11-case decision table against `paymentMethodFamily()` (same family, blank, real switch) — all pass. `php -l` clean.
- CustomerController: read-only replay on the replica — saving customer 952 as Shop without the flag → 409 listing NF-19945 (Rs 1,372), customer row and invoice untouched, no non-SELECT SQL issued. `php -l` clean. Blade compiled + linted.
- Not browser-walked.

## Not in this round
- The Rs 7,201.60 overpay credit still sits on customer 4637 (landline) while SH-23614 is on 952 — owner moves it in the UI.
- Order 18249 `address_line1` was overwritten to "213/C" by the customer picker on 14-Oct — check the order and record 952.
