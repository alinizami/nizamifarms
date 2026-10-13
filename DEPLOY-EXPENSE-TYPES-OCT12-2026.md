# DEPLOY — 🧾 Expense Types: rename / turn off / order / group from the dropdown (Oct-12-2026)

**1 SQL · 17 web (3 new) · /xclean · APK (3 mobile files).** SQL first, then web, then the APK.
Plan + owner rulings: `EXPENSE-TYPES-MANAGE-PLAN-OCT11-2026.md`.

Owner (Shabib), 11/12-Oct:
- Taimur (and only Taimur for now) can rename, turn off (never delete), reorder and group expense
  types **from a button right next to the Expense Type dropdown**, on web and phone.
- Rename relabels every past expense. Petrol and Maintenance are locked. No new permission key.
- Rename onto an existing name = **merge**, allowed only where safe. No clean-up done by Claude.
- One engine for every surface (web forms, Request Settings, Operations, Ledger Hub, Storage,
  phone manager form, phone short-cash). Rider mode only offers Petrol / Maintenance, which are
  locked, so riders see no change.

## Why the build is shaped like this
- A request stores the type as a **name**. The ledger account is built from that name at
  approval time (`EXP_<NAME>`). A plain rename would open a new account from the next posting and
  split the P&L line. So a rename **pins** the old account code on the type, and posting asks the
  engine for the account. Unrenamed names post exactly where they always did (proved for all 67).
- Nine places read the list with their own query. All now read `ExpenseTypeService`.

## 1 — SQL (local replica already has it)
`database/migrations/expense_types_manage_oct2026.sql` adds 8 nullable / defaulted columns to
`t_fin_config`: `is_active` (default 1), `sort_order`, `group_name`, `account_code`,
`former_names`, `merged_into_id`, `hidden_at`, `hidden_by`. **No data is changed.** Safe to run
twice (each column is added only if missing). The last SELECT should show all types active and
`pinned = 0`.

If the web files go up before the SQL, every list behaves exactly as before and the manage
buttons answer "the database update has not been run". Order is still: SQL first.

## 2 — Web (17 files)
| File | Change |
|------|--------|
| `app/Services/FIN/ExpenseTypeService.php` **(new)** | The one engine: lists, scope rule, order + groups, rename (relabels requests + titles, quick chips, Frozen cost tags, Storage products, account label; pins the account), merge (same scope only; never Petrol/Maintenance), old-name merge, turn off / on (last type in a scope refused), reorder, group, undo for 24 h, write guard, posting account. |
| `app/Http/Controllers/FIN/ExpenseTypeController.php` **(new)** | JSON door for the web drawer and the phone sheet. Changes need the existing mobile key `create_expense_category` (Taimur role only). |
| `resources/views/fin/expense-types/_manager.blade.php` **(new)** | The "✎ Manage types" side drawer + the dropdown renderer (group headings, keeps the current choice). |
| `routes/web.php` | 9 routes under `/finance/expense-category/types…` |
| `routes/api.php` | 8 routes under `/api/rider/expenses/types…`, declared before the `/expenses/{id}` routes. |
| `app/Services/FIN/LedgerPostingService.php` | Expense account comes from `ExpenseTypeService::accountCodeFor()` (same formula when unpinned). |
| `app/Http/Controllers/FIN/ExpenseCategoryController.php` | Add-type logic moved into the engine. Same response shape. Duplicates caught in any case; an inactive twin answers "restore it". |
| `app/Http/Controllers/API/RiderController.php` | Phone list (`/rider/expenses/categories`) from the engine, plus `types`, `can_manage`, `can_chips`. Admin short-cash list from the engine (was every name ever typed). Chip validation from the engine. Guard on `createRequest` + `settleShortCash`. |
| `app/Http/Controllers/Request/RequestController.php` | Guard on store (web form + `/api/requests/store`). |
| `app/Http/Controllers/FIN/EmployeeCashController.php` | Guard on short-cash, expense request and company payment. Employee page picker from the engine. |
| `app/Http/Controllers/Request/RequestSettingsController.php` | Old rename / "delete" endpoints go through the engine; "delete" now turns the type off. |
| `app/Http/Controllers/FIN/Hub/HubController.php` | Settle-modal picker from the engine. |
| `app/Http/Controllers/FIN/SupplyStorageController.php` | Storage product category list from the engine (+ an inactive type a product still uses). |
| `resources/views/fin/expense/index.blade.php` | ✎ button on the New Request modal, list from the engine, BU/type filter = engine renderer. |
| `resources/views/pages/requests/create.blade.php` | ✎ button, list from the engine, follows the chosen request type. Add now asks for JSON (it used to show an error after a successful add). |
| `resources/views/pages/requests/settings.blade.php` | Expense types card: engine list with groups, ✎ per request type, hard-delete chip button removed. Add now asks for JSON (same old error). |
| `resources/views/admin/operations.blade.php` | Category list from the engine + ✎ button. |

Then run **`/api/public/xclean`** (routes + Blade changed).

⚠ The web tree also holds files from OTHER rounds — upload only the files above. Not mine:
`PayrollController` (API + HR), `AttendanceController`, `FirebaseService`, `OvertimeService`, `PayrollService`,
`Concerns/TimeBankEndpoints.php`, `TimeBankService.php`, `time_bank_oct2026.sql` (time-bank round, in progress),
`WhatsAppService.php` + `BankDetailsProvider.php` (Oct-11 menu round), `Khaas/IngredientPriceService.php`.

⚠ **Shared file:** `API/RiderController.php` ALSO carries two small time-bank blocks (attendance summary +
monthly store attendance). Both are wrapped in `catch (\Throwable)` and simply switch the time-bank card off
when `TimeBankService` is not on the server, so uploading RiderController from this list before the time-bank
round goes up is safe (verified Oct-12). The reverse also holds: whichever round ships first carries the file.

## 3 — APK (3 mobile files)
| File | Change |
|------|--------|
| `src/components/ExpenseTypeManagerSheet.js` **(new)** | ✎ Manage sheet: **Types** tab (Taimur) + **Quick chips** tab (the old chip editor moved here). |
| `src/screens/StoreRequestScreen.js` | "⚙ Edit bubbles" → **"✎ Manage types"** (Taimur) or "⚙ Edit chips" (chips only). Picker shows group headings. After a change the list reloads and the current choice follows a rename. Old chip modal removed. |
| `src/components/CustomPicker.js` | `{header: true}` items = disabled group headings, never selectable. |

Old APKs keep working after the web upload: they get the same names (inactive ones gone), add a
type as before, and the old chip editor still saves.

## Proof
- `php test_expense_types_oct12.php` → **100/100** (one transaction, rolled back; replica unchanged).
- `npx jest __tests__/expenseTypesManageOct12.test.js` → **10/10**; full suite green apart from
  (`App.test.tsx` cannot load `RNGestureHandlerModule` under jest — same before this round).
- Existing harnesses: core flows 33/33, claim chain 80/80, payroll shortfall 70/70. Two-vehicle
  fuel (9 ✗), claim attribution (1 ✗), Rule P spine (2 ✗) show the **same** counts on the
  committed code — pre-existing.
- Browser, local, as Taimur: New Request modal → ✎ → rename Blade (2 past expenses) → dropdown
  updated in place → Undo restored it; groups render as headings in the dropdown; Request
  Settings and Operations render with the drawer. Replica put back afterwards.
- Device-walked on the phone as Taimur (below).

## Device walk (Oct-12 — phone as Taimur on the local replica, plus web)
Walked: ✎ Manage types · rename (confirm shows the count) → web list, history, posting account and
audit followed at once → Undo · turn off a type that was SELECTED on the form (chip + selection drop)
→ turn on · group two types (grey headings in the native dropdown; a heading cannot be picked) ·
reorder · rename the selected type (form follows) · locked Petrol (Group only) · merge an old name
("Petrol Taimur" → "Petrol - Taimur") → Undo · Quick chips tab. Replica restored afterwards.

Found and fixed during the walk:
- **Staff Salaries chip** showed on the phone (the Khaas copy rode in on the first, unscoped load).
  Every expense door refuses that name, so any type NAMED Staff Salaries now belongs to Payroll and
  is never offered. ⚠ The Khaas Expense list therefore starts EMPTY (it only held Staff Salaries).
- **Undo only lived in a 12-second toast** though the confirm promises 24 h. Drawer and sheet now
  show a standing "↶ Last change: … · Undo" line (server `last_change`), one step back per undo.
- **Turn on did not bring the quick chip back.** It now returns to its old position.
- **Keyboard hid Save** in the phone sheet (an Android modal does not resize). Rename and Group
  editors now sit in a panel pinned above the keyboard; existing headings are one-tap chips.
- **Merge picker** listed all 47 types in dropdown order. Closest matches (shared words) now come
  first on phone and web; locked types are never offered.
- Search unfolds Inactive / Old-names when they match; wording is "Turn off / Turned off"
  everywhere; after undoing a rename the selected value follows back (web and phone).

Not walked: actually submitting an expense from the phone (it would self-approve and post to the
replica ledger). The submit guard and posting account are covered by the harness instead.

## After upload — tell Taimur
- The ✎ button sits next to "Expense Type" on the New Request form (web) and the phone form.
- Renaming relabels old expenses; Undo works for 24 hours from the "Last change" line at the top.
- "Old names on past expenses" lists drift such as "Petrol Taimur" — merge them in when ready.
- Ledger line descriptions written at the time ("Expense Request #… - Food (Shabib)") keep the
  old wording; amounts, accounts and every report that groups by type follow the new name.
