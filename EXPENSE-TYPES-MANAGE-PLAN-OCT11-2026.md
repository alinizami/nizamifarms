# Expense Types — rename / inactive / order from the dropdown, web + mobile (PLAN v2, Oct-11-2026)

Status: BUILT Oct-12-2026 ✗live — O1 groups YES, O2 merge YES (with §4 refusals + undo), O3 gate = `create_expense_category` (Taimur only). Deploy: DEPLOY-EXPENSE-TYPES-OCT12-2026.md.

## 0. Owner rulings (Oct-11)

| # | Ruling |
|---|---|
| R1 | Rename relabels ALL historical records too. |
| R2 | Locked list = Petrol, Maintenance only (no rename, no hide). |
| R3 | No new permission — reuse keys Taimur already holds. Rename/hide/order = **Taimur only** for now; owner adds roles later. |
| R4 | Web + APK ship together as one round. |
| R5 | No clean-up or merges by Claude — Taimur does it by hand through the new UI. |
| R6 | No "delete forever". Inactive is the only way to retire a type. |
| R7 | Door = a button next to the dropdown, on both surfaces. Taimur must never need Request Settings or Operations for this. |
| R8 | Request Settings keeps working but through the SAME engine (no second copy of the logic). |
| R9 | Add stays exactly as today ("➕ Add New Category…" in the picker). |
| R10 | Merge (rename onto an existing name): give it to Taimur ONLY if it is safe — §4. |

## 1. The three levels — reviewed

| Level | List | Where managed today | Rename / inactive / order |
|---|---|---|---|
| 1 Request type | Expense Reimbursement, Khaas Expense, Qurbani, Leave, Salary Advance… (`t_req_category`) | Request Settings → Request Types | already all three ✅ |
| 2 **Expense type** | Petrol, Food, Roti, Maintenance… (`t_fin_config` `EXPENSE_CATEGORY_*`) | Request Settings card (add + HARD delete only); mobile add + bubbles | **the gap — this round** |
| 3 "What was done" | Oil Change, Chain Set, Repair… (`t_fleet_maintenance_types`) — exists ONLY under Maintenance, carries per-machine intervals | Bikes page → maintenance types (`manage_bike_service`) | already all three ✅ (`deleteMaintenanceType` retires, never deletes) |

Only level 2 is built in this round. Levels 1 and 3 are untouched.

## 2. Facts that shape the build (from code + replica, see v1 notes in WORKLOG Oct-11)

- A request stores the type as a **name string** (`t_req_master.expense_category`); 1,446 "Petrol", 253 "Food"… No id anywhere.
- Ledger account for an expense is **slugged from the name at posting time** (`LedgerPostingService::getOrCreateExpenseAccount` ~608; a second, different slug in `AccountModel::createExpenseAccount`). A naive rename ⇒ new `EXP_` account from the next posting ⇒ split P&L line. Replica already carries 3× `EXP_PACKAGING*_BAGS`, 2× `EXP_SOCIETY_IN*OUT…` from this.
- Names keyed elsewhere: bubble JSON (`EXPENSE_BUBBLES__<RCC>__<BU>`), `t_fin_cost_type_map` (kind `expense_category`), `t_fin_supply_product.expense_category_name`, Frozen Month Review groups by the request name (`FrozenMonthService` ~689).
- Hard-coded logic names: `Petrol` (11 refs), `Maintenance` (20 refs) — meter capture, fuel claims, service records, workshop, OnlineFollowUp. `PENDING` (admin partial-payment marker in the mobile filter), `Salary` (slip rows), `Staff Salaries` (creation blocked).
- Nine readers query the list directly: `pages/requests/create.blade:155`, `fin/expense/index.blade:1014`, `admin/operations.blade:664`, `RiderController` 5318 + 5397, `HubController:576`, `EmployeeCashController:1133`, `SupplyStorageController:361`, `RequestSettingsController` 142 + 916.
- `RequestController` validates `expense_category` as `nullable|string` — no existence check (that is how "Telecommunication", "Fuel", "Water" got onto history with no row).
- `ConfigModel::get()` caches single keys 1 h; the list queries do not use it.

### ⚠ Permission finding (changes R3's mechanics)
- Web `manage_request_settings` is held by **Manager, Management, Taimur, Shabib** roles — gating on it would let every manager rename/hide. Not "Taimur only".
- Mobile `create_expense_category` is held by **Taimur role only** (one user). Mobile `configure_expense_bubbles` = Taimur + System Access Group.
- Web add (`fin.expense-category.store`) has **no permission check at all** today (anyone logged in can add a type from the web form).
- `UserModel::hasMobilePermission()` works on the web session too (the Hub already uses it).

**Proposal (O3):** gate rename / inactive / restore / order / merge on BOTH surfaces with the existing mobile key `create_expense_category` → Taimur only today, owner grants it to others later from Roles → Mobile Permissions (already listed there). Add stays as today (R9). No SQL for permissions.

## 3. Design

### 3.1 Data — 4 nullable columns on `t_fin_config` (1 SQL, no data move)
```sql
ALTER TABLE t_fin_config
  ADD COLUMN is_active    TINYINT(1)  NOT NULL DEFAULT 1 AFTER request_category_code,
  ADD COLUMN sort_order   INT         NULL AFTER is_active,
  ADD COLUMN group_name   VARCHAR(60) NULL AFTER sort_order,
  ADD COLUMN account_code VARCHAR(50) NULL AFTER group_name,
  ADD COLUMN hidden_at    DATETIME    NULL AFTER account_code,
  ADD COLUMN hidden_by    INT         NULL AFTER hidden_at;
-- backfill: account_code = the code the posting slug produces TODAY for config_value (so nothing moves)
```
Other config keys keep `is_active=1`, NULL elsewhere. `group_name` only if O1 = yes.

### 3.2 ONE engine — `App\Services\FIN\ExpenseTypeService`
- `options(bu, rcc, ['include' => $currentName])` — the only picker source. Active, in scope, ordered (`group_name`, `sort_order`, name). `include` keeps a hidden name selectable on a request that already carries it.
- `all(bu, rcc, withHidden)` — manager list: `used_count`, `last_used_at`, `in_bubbles`, `is_system`, `is_active`.
- `create(name, bu, rcc)` — today's `ExpenseCategoryController::store` logic moves here (key generation, Khaas BU rule, Staff Salaries block, account creation) + duplicate check by **name, case-insensitive, hidden included** → "'Food' is inactive — restore it?".
- `rename(id, newName, actor)` — one transaction: config row (`config_value` only; `config_key` stays) · `account_code` pin unchanged · bubble JSON rewritten in that scope · cost-type-map key rewritten · supply products `expense_category_name` · `t_req_master.expense_category` relabelled **in scope** (R1) · audit row with the relabelled request ids. Refused for locked names, for a name that is active elsewhere in scope (→ O2 merge path), and for empty/whitespace.
- `hide(id)` / `restore(id)` — flips `is_active`, stamps `hidden_at/by`, drops the name from the bubble JSON on hide. Refused for locked names and for the last active type in a scope.
- `reorder(ids[])`, `setGroup(id, name)` (O1).
- `merge(fromId, intoId)` — §4; only if O2 = yes.
- `accountFor(name)` — pinned `account_code` when a row exists, else today's slug (legacy names keep posting exactly where they post now). Used by `LedgerPostingService` and `AccountModel::createExpenseAccount`.
- `LOCKED = ['Petrol', 'Maintenance']`; `BLOCKED_CREATE = ['Staff Salaries', 'Staff Salary']`.
- Scope = (`business_unit_id`, `request_category_code`) exactly as `getExpenseCategoriesFromConfig` resolves it today (NULL rcc = shared by `expense` + `khaas_expense`).

### 3.3 Write-side guard
`RequestController::store`/edit and `EmployeeCashController` short-cash: `expense_category` must be in `options(scope, include: current)`. Message: web "This expense type is inactive — pick another", mobile "Yeh expense type ab band hai — doosra chunein." Old APKs keep working: the list endpoint just stops returning hidden names; only a hide-between-load-and-submit race hits the message.

### 3.4 Web UI — "✎ Manage types" next to the dropdown (R7)
Both expense forms (`pages/requests/create` and the quick form in `fin/expense/index`): a small link-button `✎ Manage types` right of the "Expense Type" label, visible only with the gate key. Opens a **right-hand side drawer** (420 px, overlay, Esc/✕ closes; same drawer pattern the owner approved for Bikes). Closing re-renders the dropdown in place, keeping the current selection.

Drawer (one Blade partial, `fin/expense-types/_drawer.blade.php`, included by both forms and by Request Settings):
- Header: "Expense types · Expense Reimbursement" (scope follows the form's chosen request type), search box, `Show inactive (n)` toggle.
- Rows (active): drag handle ⋮⋮ · name (🔒 on Petrol/Maintenance, no actions) · ✎ · "Inactive" (eye-off) · small grey "used 253 · 3 Oct".
- ✎ → inline input, Enter saves, Esc cancels. Save shows a one-line confirm: "Rename Food → Meals and relabel 253 past expenses?" ✓ Rename / Cancel.
- Eye-off → instant, row slides to the inactive section, toast "Food is now inactive · Undo".
- Inactive section (collapsed under the toggle): greyed rows with `Restore`.
- Footer: `+ Add type` (reuses today's add modal).
- If O1 groups: a group pill per row (click → pick/clear group), dropdown renders `<optgroup>` headings.
- Request Settings "Expense Sub-Categories" card → the same partial inline (its old add/delete JS and the three `RequestSettingsController` sub-category actions are removed; the card stays where managers expect it, but managers without the gate key see it read-only).

### 3.5 Mobile UI — same button on `StoreRequestScreen`
- Label row: `Expense Type *` ……… `✎ Manage` (replaces "⚙ Edit bubbles"; shown with the gate key).
- Opens a full-height sheet (Modal with `maxHeight: '90%'`, see [rn-modal-unbounded-box-hides-footer]) with two tabs:
  - **Types** (default): search · active list; each row = name · ✎ · eye-off (🔒 rows show no icons). ✎ opens a small Modal with a TextInput (NOT `Alert.prompt` — truthy on Android) + the same relabel confirm with the count. Eye-off = instant with Undo bar. "Inactive (n)" collapsible with Restore. `+ Add type` at top (today's add modal).
  - **Quick chips**: today's bubble editor, unchanged.
- Picker: hidden names gone; with O1, section headers in `CustomPicker` (needs a `section` prop — check before build; fallback = "Food · Roti" style prefix in the label only).
- Endpoints under `/rider/expenses/types/*` mount the same web controller, exactly as `store` is shared today.

### 3.6 Audit
Every rename / hide / restore / merge / reorder writes `t_sys_audit_log` (`entity_type = expense_type`, `changes` = old/new + relabelled request ids + bubble/cost-map/supply counts). Rename of history is the only mass write; it is a label, never an amount.

## 4. Merge evaluation (R10 / O2) — "rename onto a name that already exists"

What a merge would do: relabel every past request of A to B (same as R1), rewrite bubbles / cost map / supply products, set A inactive, audit with the request ids. Checked against the money and the fleet:

| Concern | Finding |
|---|---|
| Amounts, balances, settlements | Untouched — only the label column on the request changes. |
| Ledger accounts | Past A postings STAY in `EXP_A`; B's pinned account takes everything from now on. Expense totals unchanged; the per-account breakdown (Hub account detail, chart of accounts) keeps showing `EXP_A` for the old months. Same situation the replica already has for the 3× Packaging accounts — no worse, and it stops growing. |
| Reports grouped by the request name (Expense Management, Frozen Month Review, assistant) | Become ONE line — the point of the merge. |
| Pending approvals of A | Approver sees B. Harmless (label). |
| Frozen cost-type map | B keeps its type if set, else inherits A's. |
| Reversibility | Audit row carries the relabelled request ids ⇒ `Undo merge` restores A's rows, A's row and its bubble slot exactly. Offered in the drawer for 24 h / until the next change on B. |
| Locked names | Merging **into or out of** Petrol/Maintenance is REFUSED: old "Fuel" rows relabelled "Petrol" would enter fuel-claim queries (`FuelClaimRules`, km-per-fill, Rule P spine) with no meter/vehicle. |
| Cross-scope | REFUSED — a Qurbani "Packaging" never merges into the shared "Packaging - Bags". |
| Case-only rename ("food" → "Food") | Plain rename, not a merge. |

**Verdict: safe to hand to Taimur** with those three refusals and the Undo. UI: when the new name already exists, the confirm reads "'Petrol Taimur' already exists as 'Petrol - Taimur'. Merge 3 past expenses into it and make 'Petrol Taimur' inactive?" ✓ Merge / Cancel. O2 = owner says yes/no.

## 5. What is NOT touched (the "don't break" list)
- `Petrol` / `Maintenance` strings in VehicleService, FleetFuelService, FuelClaimRules, BikeServiceClock, ServiceRecordService, OnlineFollowUpService, RequestController bike fields — untouched; the names cannot change.
- Level-3 maintenance types, workshop trips, meter rules, handover — untouched.
- `getExpenseCategories` (mobile ledger FILTER: `PENDING` + distinct past names) — untouched; relabel moves history so the filter follows.
- Rider's own petrol/maintenance filing screens — they only read the list; hidden names simply vanish.
- Bubble endpoint `saveExpenseBubbles` — validation list comes from `options()`; behaviour unchanged.
- Storage take-outs — keep posting to the product's `expense_category_name`; rename updates it.

## 6. Files (estimate) and deploy
Web (~13): `ExpenseTypeService` (new) · `FIN/ExpenseTypeController` (new; `ExpenseCategoryController::store` becomes a thin call) · `_drawer.blade.php` (new) · `routes/web.php` + `routes/api.php` (⇒ /xclean) · `LedgerPostingService` + `AccountModel` (`accountFor`) · `RequestController` + `EmployeeCashController` (guard) · `pages/requests/create.blade` · `fin/expense/index.blade` · `admin/operations.blade` · `pages/requests/settings.blade` + `RequestSettingsController` (card → partial, 3 actions removed) · `RiderController` (2 list methods → service) · `HubController` · `SupplyStorageController`.
Mobile (2–3): `StoreRequestScreen.js`, new `ExpenseTypeManagerSheet.js`, `CustomPicker.js` only if O1.
SQL (1): §3.1. Deploy: SQL → web → /xclean → APK, one round (R4).

## 7. Proof
Harness `test_expense_types_oct11.php`: options honour hidden/scope/include · create dup by case + hidden-restore hint · rename rewrites bubbles/cost map/supply products/history (counts) + audit · locked refused · hide last-in-scope refused · posting after rename lands in the SAME account (the §2 regression) · guard accepts an edit carrying a hidden name · merge refusals (locked, cross-scope) + undo round-trip. Jest: sheet tabs, rename modal, picker drops hidden. Browser walk on local as Taimur (web drawer) + device walk (sheet).

## 8. Open before build
- **O1 Groups** — see chat explanation; yes/no.
- **O2 Merge** — hand to Taimur with the §4 refusals + Undo, or block with "already exists"?
- **O3 Gate** — rename/inactive/order on both surfaces by the existing mobile key `create_expense_category` (Taimur only today)? (`manage_request_settings` would open it to all managers.)
