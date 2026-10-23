# DEPLOY — ✂ Split invoice (19-Oct-2026)

**1 SQL → 12 web files → `/xclean` → APK.** Nothing here is live until uploaded.

⚠⚠ **Ship together with `DEPLOY-SCAN-ORDERED-QTY-OCT19-2026.md` (frozen box count).** Four web files
(`RiderController.php`, `OrderController.php`, `pages/orders/index.blade.php`, `routes/api.php`) and the
phone's `StoreOpenOrdersScreen.js` carry BOTH rounds. Run **both** SQL files first, then upload once, then
one APK. Both rounds are dormant-safe (every reader checks its column first).

## What changes for the team

**Farooq (phone, store mode), Shabib and Taimur (phone + web).** On an open invoice that hasn't left the
store, a **✂ Split** button:
- Phone: expanded order card → Items header, next to Scan weights / Print.
- Web: Orders page → the order's action buttons (orange ✂).

Tick the items that can't go → both totals update as you tick → **Split invoice**.
- The ticked items **move** to a new invoice with the same number + `-A` (e.g. `SH-23808-A`). Nothing is
  deleted. A second split of the same invoice gives `-B`, then `-C` (always lettered off the original).
- The new invoice: status **New**, no rider, same customer / address / payment method, **no delivery
  charge**. Delivery charge, discounts, account credit and tip all stay on the original. A charge can
  still be added on the new one by hand if needed.
- Moved items keep their prepared tick and scans; no stock moves.
- **Already paid?** If the original has a payment proof (Proof received / Verified / Bank confirmed /
  amount differs), the screen asks: *"Are the items you are moving paid by the same payment?"*
  - **Yes** → the new invoice gets the same proof (badge shows "combined, 1 of 2"). Approvals reads it as
    one payment for two invoices — never as an overpayment.
  - **No** → the new invoice has no proof; the customer pays for it separately.
- If the store had already entered a **packet count**, it is reset on the original — recount and reprint
  labels for both invoices (the old QR labels carry the old number).
- Both invoices show one line: *"Split from SH-23808 — the rest of that order"* / *"Some items moved to
  SH-23808-A"* (web invoice, PDF, WhatsApp image, phone invoice). Order lists show a small ✂ chip.
- Refused (with a clear message): delivered / on the van / out for delivery / dispatched / cancelled /
  returned, already invoiced in the ledger, payments recorded (shop / Qurbani), Qurbani and Khaas storage
  orders, only one item, all items ticked, or a discount bigger than what would stay.
- The customer app shows `-A` as its own order (`23808-A`).

## Step 1 — SQL (prod, FIRST)
`database/migrations/add_order_split_oct2026.sql` — idempotent:
1. `t_crm_prod_order.split_from_order_id` (nullable) + index `idx_order_split_from`.
2. Web permission `split_orders` → roles **Management, Taimur, supervisor 2**.
3. Mobile permission `split_orders` (group `store_mode_orders`) → the same three roles.
Verification queries are at the bottom of the file (expect 1 column, 3 + 3 roles).
⚠ Role-level: everyone in `supervisor 2` gets it, not only Farooq.

## Step 2 — web files (12)
| File | Why |
|---|---|
| `app/Services/CRM/OrderSplitService.php` **(new)** | THE engine: gates, preview, split, numbering, proof link, `relationsFor()` |
| `app/Http/Controllers/CRM/OrderSplitController.php` **(new)** | one controller, routed for web AND api |
| `resources/views/pages/orders/partials/split-modal.blade.php` **(new)** | web dialog |
| `routes/web.php` | `GET /orders/{id}/split/preview`, `POST /orders/{id}/split` |
| `routes/api.php` | same two under `/api/rider/…` (⚠ also carries the Oct-19 box-count comment) |
| `resources/views/pages/orders/index.blade.php` | include + ✂ button + chip (⚠ also Oct-19 box count) |
| `resources/views/pages/orders/invoice.blade.php` | the split line on the invoice (live orders only) |
| `app/Http/Controllers/CRM/OrderController.php` | `split_from`/`split_into` on list rows, live only (⚠ also Oct-19) |
| `app/Http/Controllers/API/RiderController.php` | same two fields on the store list + details (⚠ also Oct-19) |
| `app/Http/Controllers/API/CustomerAppController.php` | `23808-A` resolves to `SH-23808-A` (was a 404) |
| `app/Http/Controllers/SysAdmin/RolePermissionController.php` | `split_orders` on the role page |
| `resources/views/pages/roles/permissions.blade.php` | `split_orders` checkbox **+ the missing `return_orders` checkbox** (see note below) |

## Step 3 — `/api/public/xclean`
New routes + Blade changes ⇒ required.

## Step 4 — APK
`StoreOpenOrdersScreen.js` (✂ button, chip, sheet mount — ⚠ also Oct-19 box count),
`components/SplitInvoiceModal.js` (new), `components/InvoiceTemplate.js` (split line),
`utils/splitInvoice.js` (new). **Web first:** an old APK simply has no button; a new APK on an old
server gets a 404 on preview and shows the message.

## Proofs
- `test_order_split_oct19.php` — **52/52** on the replica, one transaction, rolled back: permission per
  user, preview totals, real split (lines keep ids/prep/stock flag, totals add up, shipping stays, packet
  reset, status history, audit on both, zero ledger rows), A→B→C lettering off the root, all refusals,
  proof yes/no (combined badge, shares add up to 9,800), both HTTP doors.
- `test_order_split_review_oct19.php` (owner's "review carefully" pass) — **33/33**, rolled back: the REAL
  create path numbers the next order `NF-20048` while `NF-20047-A` exists (strict sql_mode, no error); a web edit
  form opened before the split gets **409** and re-adds nothing; all 18 customer/address/payment fields identical
  on the new invoice and 13 money/custody fields empty; delivering BOTH halves posts two separate ledger invoices
  (Rs 2,480 + Rs 19,335 = the old 21,815, same rider account, no duplicates); online half lands in Online
  Approvals as `pending_l1` with its own amount; invoice send plan carries `SH-23808-A`; customer-app lookup;
  store list row has every key the phone card reads.
- Jest `__tests__/splitInvoice.test.js` 7/7; full suite 1,051/1,051 (App.test.tsx fails before and after —
  gesture-handler native module under jest, unrelated). Phone: a split also clears the original's prep ticks
  (stale line ids were already ignored server-side).
- Browser on the local replica as Shabib: ✂ on 25 open orders; split SH-23808 (2 items) through the
  button → SH-23808-A created, audit `web`/user 79, both rows show chips, both invoices print the line;
  phone API payloads checked as Farooq. Local data then restored exactly.
- ✅ **Device-walked Oct-19** on the owner's Samsung S21 (debug build on Metro, local server via adb
  reverse, logged in as Taimur): ✂ Split on the SH-23808 card → sheet → ticked 2 items → server totals
  (14,955 / 6,860) → Split → "SH-23808-A created" → list refetched by itself: original Rs 14,955 · 0/6 ·
  "✂ → SH-23808-A"; new card right under it, Rs 6,860, Unassigned, 0/2, "✂ from SH-23808", same pin and
  address; Invoice button drew the phone invoice with the split line, Free Delivery, Rs 6,860. DB: audit
  rows source=mobile, 0 ledger rows, webhook outbox flat (pushes + webhooks were OFF). Local data restored.

## Notes for the owner
- ✅ **Fixed (owner go, Oct-19):** `return_orders` was in the role-permission list with **no checkbox**,
  so every Save of a role page silently turned Returns OFF. It now has its checkbox (Orders group).
  Proof `test_role_page_keeps_returns_oct19.php`: Management page renders it ticked; a real Save keeps
  `return_orders` and `split_orders` at 1 (rolled back).
  ⚠ **10 more keys have the same bug, NOT changed:** `manage_payroll`, `assign_vehicles`,
  `manage_bike_service`, `manage_vehicle_tickets`, `schedule_workshop`, `receive_workshop_alerts`,
  `view_bike_costs`, `view_khaas_sales_report`, `web_menu_invoices_analysis`, `web_menu_finance_hub`.
  Until fixed, **don't press Save on the Management / Taimur / khaas / Adnan / supervisor 2 role pages** —
  it switches these off for that role.
- The WhatsApp "we've split your invoice" template is parked (owner will provide the wording). The
  engine's result already has both numbers; the notifier is planned on the `OrderCancellationNotifier`
  pattern, sent from the split itself so web and phone get it together.
- Shopify fulfilment write-back is OFF on prod, so the `-A` vs Shopify question does not arise today.
  If it is ever switched on, delivering the original would mark the whole Shopify order fulfilled.
