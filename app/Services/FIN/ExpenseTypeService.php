<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Expense Types — the ONE engine behind every "Expense Type" list (Oct-2026).
 *
 * An expense type (Petrol, Food, Roti…) is a t_fin_config row whose config_key starts with
 * EXPENSE_CATEGORY_. Its NAME is what travels: a request stores the name string on
 * t_req_master.expense_category (no id), the mobile quick chips store names, the Frozen
 * cost-type map and Storage products store names.
 *
 * Every picker on every surface (web forms, Ledger Hub, Storage, mobile manager form, mobile
 * short-cash) reads options()/names() from here, so a rename / inactive / order / group made
 * anywhere shows the same everywhere. The rider screen only ever offers Petrol / Maintenance,
 * which are LOCKED here and therefore can never change under it.
 *
 * ⚠⚠ The ledger account is derived from the NAME at posting time (EXP_<NAME>). A rename would
 *    open a brand-new account from the next posting and split the P&L line, so rename PINS
 *    account_code first and accountCodeFor() is what LedgerPostingService posts to.
 *
 * ⚠ Inactive = no longer offered. Nothing is ever deleted; history, filters and reports keep
 *   every old row. A request already carrying an inactive name stays valid.
 *
 * The 8 columns ship in database/migrations/expense_types_manage_oct2026.sql. Until that SQL
 * has run, available() is false: every list behaves exactly as before and the manage actions
 * refuse with a clear message.
 */
class ExpenseTypeService
{
    public const KEY_PREFIX = 'EXPENSE_CATEGORY_';
    /** Salaries go through Payroll — never offered in a picker (same rule as before). */
    public const PAYROLL_KEY = 'EXPENSE_CATEGORY_STAFF_SALARIES';
    /** Bike / fuel / workshop logic keys on these exact names — no rename, no inactive, no merge. */
    public const LOCKED = ['petrol', 'maintenance'];
    /** Names nobody may create or rename TO. */
    public const BLOCKED = ['staff salaries', 'staff salary', 'pending', 'salary', 'salary advance'];

    /** Existing mobile permission keys (owner ruling: no new key). */
    public const MANAGE_PERMISSION = 'create_expense_category';
    public const BUBBLES_PERMISSION = 'configure_expense_bubbles';

    public const AUDIT_ENTITY = 'expense_type';
    public const UNDO_HOURS = 24;
    public const NAME_MAX = 100;
    public const GROUP_MAX = 60;

    /** Original request types share the NULL-scoped types (same rule as the old query). */
    private const ORIGINAL_TYPES = ['expense', 'khaas_expense'];

    private static ?bool $available = null;

    // =====================================================================================
    // Availability + permissions
    // =====================================================================================

    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Schema::hasColumn('t_fin_config', 'is_active')
                    && Schema::hasColumn('t_fin_config', 'former_names');
            } catch (\Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** Test hook — a harness that adds/removes the columns re-reads them. */
    public static function forgetAvailability(): void
    {
        self::$available = null;
    }

    public static function canManage($user = null): bool
    {
        $user = $user ?: auth()->user();
        try {
            return $user && method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission(self::MANAGE_PERMISSION);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function canEditChips($user = null): bool
    {
        $user = $user ?: auth()->user();
        try {
            return $user && method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission(self::BUBBLES_PERMISSION);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // =====================================================================================
    // Reading
    // =====================================================================================

    /** Every expense-type row, normalised. Tiny table (≈70 rows) — read fresh each call. */
    public static function rows(): array
    {
        $cols = ['id', 'config_key', 'config_value', 'business_unit_id'];
        try {
            if (Schema::hasColumn('t_fin_config', 'request_category_code')) {
                $cols[] = 'request_category_code';
            }
        } catch (\Throwable $e) {
            // ignore — old schema
        }
        if (self::available()) {
            $cols = array_merge($cols, ['is_active', 'sort_order', 'group_name', 'account_code', 'former_names', 'merged_into_id', 'hidden_at']);
        }

        return DB::table('t_fin_config')
            ->where('config_key', 'LIKE', 'EXPENSE\_CATEGORY\_%')
            ->get($cols)
            ->map(fn ($r) => self::normalise($r))
            ->all();
    }

    private static function normalise($r): array
    {
        $former = [];
        if (!empty($r->former_names)) {
            $dec = json_decode((string) $r->former_names, true);
            $former = is_array($dec) ? array_values(array_filter($dec, 'is_string')) : [];
        }
        $name = (string) $r->config_value;

        return [
            'id'             => (int) $r->id,
            'key'            => (string) $r->config_key,
            'name'           => $name,
            'bu_raw'         => $r->business_unit_id,
            'bu'             => (int) ($r->business_unit_id ?? 1),
            'rcc'            => ($r->request_category_code ?? null) ?: null,
            'active'         => !isset($r->is_active) || (int) $r->is_active === 1,
            'sort'           => isset($r->sort_order) ? (int) $r->sort_order : null,
            'group'          => isset($r->group_name) && trim((string) $r->group_name) !== '' ? trim((string) $r->group_name) : null,
            'account_code'   => ($r->account_code ?? null) ?: null,
            'former'         => $former,
            'merged_into_id' => isset($r->merged_into_id) ? (int) $r->merged_into_id : null,
            'hidden_at'      => $r->hidden_at ?? null,
            'locked'         => self::isLocked($name),
            // Salaries go through Payroll: every expense door refuses the name "Staff Salaries"
            // (any business unit), so a row with that name is never offered or managed — the
            // Khaas copy used to sneak into the phone's first, unscoped load as a quick chip.
            'payroll'        => $r->config_key === self::PAYROLL_KEY
                || in_array(mb_strtolower(trim($name)), ['staff salaries', 'staff salary'], true),
        ];
    }

    public static function isLocked(?string $name): bool
    {
        return in_array(self::lower($name), self::LOCKED, true);
    }

    /** Same scope rule the old getExpenseCategoriesFromConfig query used. */
    public static function inScope(array $row, $bu, $rcc): bool
    {
        if ($bu !== null && $bu !== '' && (int) $bu > 0) {
            if ((int) $bu === 1) {
                if (!($row['bu_raw'] === null || (int) $row['bu_raw'] === 1)) {
                    return false;
                }
            } elseif ($row['bu'] !== (int) $bu) {
                return false;
            }
        }
        if ($rcc !== null && $rcc !== '') {
            if (in_array($rcc, self::ORIGINAL_TYPES, true)) {
                if (!($row['rcc'] === null || $row['rcc'] === $rcc)) {
                    return false;
                }
            } elseif ($row['rcc'] !== $rcc) {
                return false;
            }
        }
        return true;
    }

    /**
     * Display order. Manual sort_order first (NULL = after every ordered row), then name.
     * When ANY row in the list has a group, rows are clustered by group — groups in the
     * order their first member appears, ungrouped rows last under no heading.
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $sa = $a['sort'] ?? PHP_INT_MAX;
            $sb = $b['sort'] ?? PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $hasGroups = false;
        foreach ($rows as $r) {
            if ($r['group'] !== null) {
                $hasGroups = true;
                break;
            }
        }
        if (!$hasGroups) {
            return $rows;
        }

        $buckets = [];
        foreach ($rows as $r) {
            $g = $r['group'] !== null ? self::lower($r['group']) : "\u{10FFFF}";
            $buckets[$g][] = $r;
        }
        // Ungrouped bucket goes last; other buckets keep first-appearance order.
        $out = [];
        foreach ($buckets as $g => $members) {
            if ($g !== "\u{10FFFF}") {
                array_push($out, ...$members);
            }
        }
        if (isset($buckets["\u{10FFFF}"])) {
            array_push($out, ...$buckets["\u{10FFFF}"]);
        }
        return $out;
    }

    /**
     * THE picker list: active types for a scope, in display order.
     * $include keeps one extra name selectable (a request that already carries an inactive name).
     *
     * @return array<int, array{id:int|null,name:string,group:string|null,locked:bool,inactive:bool}>
     */
    public static function options($bu = null, $rcc = null, ?string $include = null, bool $withPayroll = false): array
    {
        $rows = array_filter(self::rows(), function ($r) use ($bu, $rcc, $withPayroll) {
            return $r['active'] && ($withPayroll || !$r['payroll']) && self::inScope($r, $bu, $rcc);
        });

        $out = [];
        $seen = [];
        foreach (self::sortRows(array_values($rows)) as $r) {
            $k = self::lower($r['name']);
            if (isset($seen[$k])) {
                continue; // same name in two BUs when no BU filter — show once
            }
            $seen[$k] = true;
            $out[] = ['id' => $r['id'], 'name' => $r['name'], 'group' => $r['group'], 'locked' => $r['locked'], 'inactive' => false];
        }

        $include = $include !== null ? trim($include) : '';
        if ($include !== '' && !isset($seen[self::lower($include)])) {
            $out[] = ['id' => null, 'name' => $include, 'group' => null, 'locked' => false, 'inactive' => true];
        }
        return $out;
    }

    /** Just the names, in display order. */
    public static function names($bu = null, $rcc = null, ?string $include = null, bool $withPayroll = false): array
    {
        return array_column(self::options($bu, $rcc, $include, $withPayroll), 'name');
    }

    /**
     * Picker payload for a web page: EVERY active type with its scope, so the page can filter
     * by business unit + request type on the client exactly like options() does.
     */
    public static function webPayload(): array
    {
        $rows = array_filter(self::rows(), fn ($r) => $r['active'] && !$r['payroll']);
        return array_map(fn ($r) => [
            'id'     => $r['id'],
            'name'   => $r['name'],
            'group'  => $r['group'],
            'bu'     => $r['bu_raw'] === null ? null : (int) $r['bu_raw'],
            'rcc'    => $r['rcc'],
            'locked' => $r['locked'],
        ], self::sortRows(array_values($rows)));
    }

    /**
     * The manager list for a scope: active + inactive, with usage, plus "old names" that sit
     * on past expenses but have no type any more (drift the manager can merge in).
     */
    public static function managerList($bu, $rcc): array
    {
        $bu = (int) ($bu ?: 1);
        $all = self::rows();
        $usage = self::usage();

        $rows = array_values(array_filter($all, fn ($r) => !$r['payroll'] && self::inScope($r, $bu, $rcc)));
        $byId = [];
        foreach ($all as $r) {
            $byId[$r['id']] = $r;
        }

        $map = function ($r) use ($usage, $byId) {
            $u = $usage[self::lower($r['name'])] ?? ['count' => 0, 'last' => null];
            return [
                'id'          => $r['id'],
                'name'        => $r['name'],
                'group'       => $r['group'],
                'active'      => $r['active'],
                'locked'      => $r['locked'],
                'scope'       => $r['rcc'] === null ? 'shared' : $r['rcc'],
                'used'        => $u['count'],
                'last_used'   => $u['last'],
                'former'      => $r['former'],
                'merged_into' => $r['merged_into_id'] && isset($byId[$r['merged_into_id']]) ? $byId[$r['merged_into_id']]['name'] : null,
                'hidden_at'   => $r['hidden_at'],
            ];
        };

        $sorted = self::sortRows($rows);
        $active = array_values(array_map($map, array_filter($sorted, fn ($r) => $r['active'])));
        $inactive = array_values(array_map($map, array_filter($sorted, fn ($r) => !$r['active'])));

        $groups = [];
        foreach ($all as $r) {
            if ($r['group'] !== null) {
                $groups[self::lower($r['group'])] = $r['group'];
            }
        }
        natcasesort($groups);

        return [
            'types'    => $active,
            'inactive' => $inactive,
            'orphans'  => self::orphans($bu, $all, $usage),
            'groups'   => array_values($groups),
        ];
    }

    /** name(lower) => {count, last} over every past request that carries a type name. */
    private static function usage(): array
    {
        $out = [];
        $rows = DB::table('t_req_master')
            ->whereNotNull('expense_category')->where('expense_category', '!=', '')
            ->selectRaw('expense_category AS n, COUNT(*) AS c, MAX(COALESCE(expense_date, created_at)) AS last_at')
            ->groupBy('expense_category')
            ->get();
        foreach ($rows as $r) {
            $k = self::lower($r->n);
            $prev = $out[$k] ?? ['count' => 0, 'last' => null];
            $last = $r->last_at ? substr((string) $r->last_at, 0, 10) : null;
            $out[$k] = [
                'count' => $prev['count'] + (int) $r->c,
                'last'  => max((string) $prev['last'], (string) $last) ?: null,
            ];
        }
        return $out;
    }

    /** Names on past expense-form requests of this BU that match no type (current or former). */
    private static function orphans(int $bu, array $all, array $usage): array
    {
        $known = [];
        foreach ($all as $r) {
            $known[self::lower($r['name'])] = true;
            foreach ($r['former'] as $f) {
                $known[self::lower($f)] = true;
            }
        }
        foreach (self::BLOCKED as $b) {
            $known[$b] = true;
        }

        $q = DB::table('t_req_master as r')
            ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
            ->whereNotNull('r.expense_category')->where('r.expense_category', '!=', '')
            ->where(function ($w) {
                $w->whereIn('c.category_code', ['expense', 'khaas_expense', 'qurbani']);
                try {
                    if (Schema::hasColumn('t_req_category', 'form_type')) {
                        $w->orWhere('c.form_type', 'expense');
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            });
        if ($bu === 1) {
            $otherBus = array_values(array_unique(array_filter(array_map(fn ($r) => $r['bu'], $all), fn ($b) => $b !== 1)));
            $q->where(function ($w) use ($otherBus) {
                $w->whereNull('r.business_unit_id')->orWhereNotIn('r.business_unit_id', $otherBus ?: [-1]);
            });
        } else {
            $q->where('r.business_unit_id', $bu);
        }

        $out = [];
        foreach ($q->distinct()->pluck('r.expense_category') as $name) {
            $k = self::lower($name);
            if (isset($known[$k]) || isset($out[$k])) {
                continue;
            }
            $u = $usage[$k] ?? ['count' => 0, 'last' => null];
            $out[$k] = ['name' => (string) $name, 'used' => $u['count'], 'last_used' => $u['last']];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public static function find(int $id): ?array
    {
        foreach (self::rows() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    // =====================================================================================
    // Ledger account (LedgerPostingService posts through this)
    // =====================================================================================

    /** The posting slug — byte-for-byte the formula LedgerPostingService has always used. */
    public static function slugCode(string $name): string
    {
        $code = 'EXP_' . strtoupper(str_replace([' ', '-', '.', '/', '(', ')'], '_', $name));
        return substr($code, 0, 50);
    }

    /**
     * Which EXP_ account an expense with this name posts to. A pinned type (renamed, merged
     * into, or created after Oct-2026) answers with its pin; everything else gets the slug,
     * so an unpinned name posts exactly where it always did.
     */
    public static function accountCodeFor(?string $name, $bu = null): string
    {
        $name = (string) $name;
        if (!self::available() || trim($name) === '') {
            return self::slugCode($name);
        }
        try {
            $row = self::resolveRow($name, $bu);
            if ($row) {
                if ($row['merged_into_id']) {
                    $target = self::find($row['merged_into_id']);
                    if ($target) {
                        $row = $target;
                    }
                }
                return $row['account_code'] ?: self::slugCode($row['name']);
            }
        } catch (\Throwable $e) {
            Log::warning('ExpenseTypeService::accountCodeFor fell back to slug', ['name' => $name, 'error' => $e->getMessage()]);
        }
        return self::slugCode($name);
    }

    /**
     * The row a name belongs to, preferring the request's business unit: exact current name
     * first (active before inactive), then a former name.
     */
    public static function resolveRow(string $name, $bu = null): ?array
    {
        $k = self::lower($name);
        $rows = self::rows();
        $bu = (int) ($bu ?: 1);

        $pick = function (callable $match) use ($rows, $bu) {
            $cands = array_values(array_filter($rows, $match));
            if (!$cands) {
                return null;
            }
            usort($cands, function ($a, $b) use ($bu) {
                $ab = $a['bu'] === $bu ? 0 : ($a['bu'] === 1 ? 1 : 2);
                $bb = $b['bu'] === $bu ? 0 : ($b['bu'] === 1 ? 1 : 2);
                if ($ab !== $bb) {
                    return $ab <=> $bb;
                }
                return ((int) $b['active']) <=> ((int) $a['active']);
            });
            return $cands[0];
        };

        return $pick(fn ($r) => self::lower($r['name']) === $k)
            ?? $pick(fn ($r) => in_array($k, array_map([self::class, 'lower'], $r['former']), true));
    }

    // =====================================================================================
    // Write-side guard (request create, short-cash, company payment)
    // =====================================================================================

    /**
     * @return array{ok:bool,name:?string,message:?string}
     *   ok + name: what to store (an old name is mapped to the type's current name)
     *   !ok: the type was made inactive — the caller refuses with message
     * Unknown free text passes through untouched (same as before this round).
     */
    public static function checkForWrite(?string $name, $bu = null, ?string $currentValue = null): array
    {
        if ($name === null || trim($name) === '' || !self::available()) {
            return ['ok' => true, 'name' => $name, 'message' => null];
        }
        if ($currentValue !== null && self::lower($currentValue) === self::lower($name)) {
            return ['ok' => true, 'name' => $name, 'message' => null];
        }
        try {
            $k = self::lower($name);
            $row = self::resolveRow($name, $bu);
            if (!$row) {
                return ['ok' => true, 'name' => $name, 'message' => null];
            }
            $isCurrent = self::lower($row['name']) === $k;

            if ($row['active']) {
                return ['ok' => true, 'name' => $isCurrent ? $name : $row['name'], 'message' => null];
            }
            if ($row['merged_into_id']) {
                $target = self::find($row['merged_into_id']);
                if ($target && $target['active']) {
                    return ['ok' => true, 'name' => $target['name'], 'message' => null];
                }
            }
            $shown = $isCurrent ? $name : $row['name'];
            return [
                'ok' => false,
                'name' => null,
                'message' => "“{$shown}” band kar diya gaya hai — doosra expense type chunein. (This expense type is inactive — please pick another.)",
            ];
        } catch (\Throwable $e) {
            Log::warning('ExpenseTypeService::checkForWrite passed through after error', ['name' => $name, 'error' => $e->getMessage()]);
            return ['ok' => true, 'name' => $name, 'message' => null];
        }
    }

    // =====================================================================================
    // Create (moved from ExpenseCategoryController::store — same rules)
    // =====================================================================================

    /**
     * @return array{ok:bool,status:int,message:string,category?:string,account_code?:string,restore_id?:int}
     */
    public static function create(string $name, $bu = null, ?string $rcc = null, $user = null): array
    {
        $name = self::cleanName($name);
        $bu = (int) ($bu ?: 1);
        if ($name === '') {
            return self::fail('Please enter a name.', 422);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return self::fail('Name is too long (max ' . self::NAME_MAX . ' characters).', 422);
        }
        if (in_array(self::lower($name), ['staff salaries', 'staff salary'], true)) {
            return self::fail('Staff salaries are handled by the Payroll screen and cannot be added as an expense category.', 422);
        }
        if (in_array(self::lower($name), self::BLOCKED, true)) {
            return self::fail("“{$name}” is a reserved name.", 422);
        }

        // Khaas (non-default BU): Taimur / Admin role only — unchanged rule.
        if ($bu !== 1) {
            $user = $user ?: auth()->user();
            $ok = $user && $user->roles()
                ->where(function ($q) {
                    $q->whereRaw('LOWER(urole_name) = ?', ['taimur'])->orWhereRaw('LOWER(urole_name) = ?', ['admin']);
                })->exists();
            if (!$ok) {
                return self::fail('Only Taimur/Admin can create new expense categories for this business unit.', 403);
            }
        }

        // Duplicate by NAME (any case, inactive included) within the business unit's family.
        foreach (self::rows() as $r) {
            if ($r['payroll'] || self::lower($r['name']) !== self::lower($name)) {
                continue;
            }
            if (($bu === 1 && $r['bu'] === 1) || ($bu !== 1 && $r['bu'] === $bu)) {
                if (!$r['active']) {
                    return ['ok' => false, 'status' => 409, 'message' => "“{$r['name']}” already exists but is inactive. Restore it instead.", 'restore_id' => $r['id']];
                }
                return self::fail("Expense category '{$r['name']}' already exists!", 400);
            }
        }

        $base = ($bu !== 1 ? 'EXPENSE_CATEGORY_KHAAS_' : self::KEY_PREFIX)
            . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $name));
        $key = self::uniqueKey($base);
        $code = self::slugCode($name);

        DB::beginTransaction();
        try {
            $account = AccountModel::firstOrCreate(
                ['account_code' => $code],
                [
                    'account_name'     => 'Expense - ' . $name,
                    'account_type'     => AccountModel::TYPE_EXPENSE,
                    'account_category' => AccountModel::CATEGORY_EXPENSE,
                    'is_active'        => 1,
                    'created_by'       => auth()->id() ?? 1,
                ]
            );

            $data = [
                'config_key'       => $key,
                'config_value'     => $name,
                'description'      => "Expense category: {$name}. Account: {$account->account_code}",
                'business_unit_id' => $bu,
            ];
            if ($rcc) {
                $data['request_category_code'] = $rcc;
            }
            if (self::available()) {
                $data['is_active'] = 1;
                $data['account_code'] = $account->account_code;
            }
            DB::table('t_fin_config')->insert($data);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creating expense category: ' . $e->getMessage());
            return self::fail('Error creating expense category: ' . $e->getMessage(), 500);
        }

        Log::info('Expense category created', ['category' => $name, 'config_key' => $key, 'account_code' => $code, 'business_unit_id' => $bu, 'created_by' => auth()->id()]);
        self::audit('created', null, $name, ['name' => $name, 'bu' => $bu, 'rcc' => $rcc], "Added “{$name}”");

        return [
            'ok' => true, 'status' => 200,
            'message' => "✓ Expense category '{$name}' created successfully!",
            'category' => $name, 'account_code' => $code,
        ];
    }

    private static function uniqueKey(string $base): string
    {
        $base = substr($base, 0, 95);
        $key = $base;
        $n = 2;
        while (DB::table('t_fin_config')->where('config_key', $key)->exists()) {
            $key = $base . '_' . $n++;
        }
        return $key;
    }

    // =====================================================================================
    // Rename / merge (one confirm screen decides which)
    // =====================================================================================

    /**
     * What a rename would do, for the confirm line. Never writes.
     * @return array{ok:bool,action?:string,message:string,count?:int,into?:array}
     */
    public static function previewRename(int $id, string $newName): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $row = self::find($id);
        if (!$row || $row['payroll']) {
            return self::fail('Expense type not found.', 404);
        }
        $new = self::cleanName($newName);
        $err = self::renameRefusal($row, $new);
        if ($err) {
            return self::fail($err, 422);
        }

        $count = self::relabelQuery($row['name'], $row)->count();
        $twin = self::twin($row, $new);
        if ($twin) {
            $why = self::mergeRefusal($row, $twin);
            if ($why) {
                return self::fail($why, 422);
            }
            return [
                'ok' => true, 'action' => 'merge', 'count' => $count,
                'into' => ['id' => $twin['id'], 'name' => $twin['name'], 'active' => $twin['active']],
                'message' => "“{$twin['name']}” already exists" . ($twin['active'] ? '' : ' (turned off — it will be turned on)')
                    . ". Merge {$count} past expense" . ($count === 1 ? '' : 's')
                    . " of “{$row['name']}” into it and make “{$row['name']}” inactive?",
            ];
        }

        return [
            'ok' => true, 'action' => 'rename', 'count' => $count,
            'message' => "Rename “{$row['name']}” to “{$new}” and relabel {$count} past expense" . ($count === 1 ? '' : 's') . '?',
        ];
    }

    /**
     * Rename a type. When the new name is another type of the same scope it becomes a merge,
     * which only runs with $confirmMerge (the UI shows previewRename()'s line first).
     */
    public static function rename(int $id, string $newName, bool $confirmMerge = false): array
    {
        $preview = self::previewRename($id, $newName);
        if (!$preview['ok']) {
            return $preview;
        }
        $row = self::find($id);
        $new = self::cleanName($newName);

        if ($preview['action'] === 'merge') {
            if (!$confirmMerge) {
                return ['ok' => false, 'status' => 409, 'needs_merge' => true] + $preview;
            }
            return self::mergeRows($row, self::find($preview['into']['id']));
        }

        $old = $row['name'];
        $pin = $row['account_code'] ?: self::slugCode($old);
        $former = self::addFormer($row['former'], [$old]);
        $former = array_values(array_filter($former, fn ($f) => self::lower($f) !== self::lower($new)));

        try {
            $undo = DB::transaction(function () use ($row, $old, $new, $pin, $former) {
                DB::table('t_fin_config')->where('id', $row['id'])->update([
                    'config_value' => $new,
                    'account_code' => $pin,
                    'former_names' => $former ? json_encode($former) : null,
                    'updated_at'   => now(),
                ]);
                $undo = [
                    'kind' => 'rename',
                    'row'  => ['id' => $row['id'], 'name' => $old, 'account_code' => $row['account_code'], 'former' => $row['former']],
                    'to'   => $new,
                ];
                $undo += self::moveName($old, $new, $row, null);
                $undo['account'] = self::renameAccountLabel($pin, $old, $new, $row['id']);
                return $undo;
            });
        } catch (\Throwable $e) {
            Log::error('Expense type rename failed', ['id' => $id, 'error' => $e->getMessage()]);
            return self::fail('Rename failed: ' . $e->getMessage(), 500);
        }

        $n = count($undo['req_ids'] ?? []);
        $auditId = self::audit('renamed', $row['id'], "{$old} → {$new}", $undo, "Renamed “{$old}” to “{$new}” · {$n} past expenses relabelled");

        return ['ok' => true, 'status' => 200, 'action' => 'rename', 'relabelled' => $n, 'undo_id' => $auditId,
            'message' => "Renamed to “{$new}” · {$n} past expense" . ($n === 1 ? '' : 's') . ' relabelled'];
    }

    /** Merge a name that has NO type any more (drift on old expenses) into a type. */
    public static function mergeOrphan(string $orphanName, int $intoId, $bu = null): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $into = self::find($intoId);
        $orphan = trim($orphanName);
        if (!$into || $into['payroll']) {
            return self::fail('Expense type not found.', 404);
        }
        if ($orphan === '') {
            return self::fail('Nothing to merge.', 422);
        }
        if ($into['locked'] || self::isLocked($orphan)) {
            return self::fail('Petrol and Maintenance drive the bike and fuel records — nothing can be merged into or out of them.', 422);
        }
        if (in_array(self::lower($orphan), self::BLOCKED, true)) {
            return self::fail("“{$orphan}” is a system name and cannot be merged.", 422);
        }
        if (self::resolveRow($orphan, $into['bu'])) {
            return self::fail("“{$orphan}” is still an expense type — rename that type instead.", 422);
        }

        try {
            $undo = DB::transaction(function () use ($orphan, $into) {
                $former = self::addFormer($into['former'], [$orphan]);
                DB::table('t_fin_config')->where('id', $into['id'])->update([
                    'former_names' => json_encode($former),
                    'account_code' => $into['account_code'] ?: self::slugCode($into['name']),
                    'updated_at'   => now(),
                ]);
                $undo = [
                    'kind' => 'orphan_merge',
                    'into' => ['id' => $into['id'], 'name' => $into['name'], 'account_code' => $into['account_code'], 'former' => $into['former']],
                    'from' => $orphan,
                ];
                return $undo + self::moveName($orphan, $into['name'], $into, $orphan);
            });
        } catch (\Throwable $e) {
            Log::error('Expense type orphan merge failed', ['orphan' => $orphan, 'into' => $intoId, 'error' => $e->getMessage()]);
            return self::fail('Merge failed: ' . $e->getMessage(), 500);
        }

        $n = count($undo['req_ids'] ?? []);
        $auditId = self::audit('merged', $into['id'], "{$orphan} → {$into['name']}", $undo, "Merged old name “{$orphan}” into “{$into['name']}” · {$n} past expenses relabelled");

        return ['ok' => true, 'status' => 200, 'relabelled' => $n, 'undo_id' => $auditId,
            'message' => "“{$orphan}” merged into “{$into['name']}” · {$n} past expense" . ($n === 1 ? '' : 's') . ' relabelled'];
    }

    private static function mergeRows(array $from, array $into): array
    {
        try {
            $undo = DB::transaction(function () use ($from, $into) {
                $fromPin = $from['account_code'] ?: self::slugCode($from['name']);
                $intoPin = $into['account_code'] ?: self::slugCode($into['name']);
                $former = self::addFormer($into['former'], array_merge([$from['name']], $from['former']));

                DB::table('t_fin_config')->where('id', $into['id'])->update([
                    'former_names' => json_encode($former),
                    'account_code' => $intoPin,
                    'is_active'    => 1,
                    'merged_into_id' => null,
                    'updated_at'   => now(),
                ]);
                DB::table('t_fin_config')->where('id', $from['id'])->update([
                    'is_active'      => 0,
                    'merged_into_id' => $into['id'],
                    'account_code'   => $fromPin,
                    'hidden_at'      => now(),
                    'hidden_by'      => auth()->id(),
                    'updated_at'     => now(),
                ]);
                $undo = [
                    'kind' => 'merge',
                    'from' => ['id' => $from['id'], 'name' => $from['name'], 'account_code' => $from['account_code'], 'active' => $from['active'], 'merged_into_id' => $from['merged_into_id'], 'hidden_at' => $from['hidden_at']],
                    'into' => ['id' => $into['id'], 'name' => $into['name'], 'account_code' => $into['account_code'], 'former' => $into['former'], 'active' => $into['active'], 'merged_into_id' => $into['merged_into_id']],
                ];
                return $undo + self::moveName($from['name'], $into['name'], $from, null);
            });
        } catch (\Throwable $e) {
            Log::error('Expense type merge failed', ['from' => $from['id'], 'into' => $into['id'], 'error' => $e->getMessage()]);
            return self::fail('Merge failed: ' . $e->getMessage(), 500);
        }

        $n = count($undo['req_ids'] ?? []);
        $auditId = self::audit('merged', $from['id'], "{$from['name']} → {$into['name']}", $undo, "Merged “{$from['name']}” into “{$into['name']}” · {$n} past expenses relabelled");

        return ['ok' => true, 'status' => 200, 'action' => 'merge', 'relabelled' => $n, 'undo_id' => $auditId,
            'message' => "Merged into “{$into['name']}” · {$n} past expense" . ($n === 1 ? '' : 's') . ' relabelled'];
    }

    private static function renameRefusal(array $row, string $new): ?string
    {
        if ($row['locked']) {
            return "{$row['name']} is used by the bike, fuel and workshop records, so it cannot be renamed.";
        }
        if ($new === '') {
            return 'Please enter a name.';
        }
        if (mb_strlen($new) > self::NAME_MAX) {
            return 'Name is too long (max ' . self::NAME_MAX . ' characters).';
        }
        if ($new === $row['name']) {
            return 'That is already its name.';
        }
        if (self::isLocked($new)) {
            return "“{$new}” is used by the bike and fuel records — nothing can be renamed or merged into it.";
        }
        if (in_array(self::lower($new), self::BLOCKED, true)) {
            return "“{$new}” is a reserved name.";
        }
        return null;
    }

    /** Another type in the same business unit already called $new (case-insensitive). */
    private static function twin(array $row, string $new): ?array
    {
        foreach (self::rows() as $r) {
            if ($r['id'] !== $row['id'] && !$r['payroll'] && $r['bu'] === $row['bu'] && self::lower($r['name']) === self::lower($new)) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Merging is refused across scopes: a Qurbani type never merges into a shop type. A
     * shop-specific type MAY merge into a shared one (the shared one is offered there too).
     */
    private static function mergeRefusal(array $from, array $into): ?string
    {
        if ($from['locked'] || $into['locked']) {
            return 'Petrol and Maintenance drive the bike and fuel records — nothing can be merged into or out of them.';
        }
        if ($from['bu'] !== $into['bu']) {
            return "“{$into['name']}” belongs to another business unit — merge is not allowed.";
        }
        $sameScope = $from['rcc'] === $into['rcc']
            || ($into['rcc'] === null && in_array($from['rcc'], self::ORIGINAL_TYPES, true));
        if (!$sameScope) {
            return "“{$into['name']}” belongs to a different request type (" . ($into['rcc'] ?: 'shared') . ') — merge is not allowed. Pick a different name.';
        }
        return null;
    }

    // =====================================================================================
    // Inactive / restore / order / group
    // =====================================================================================

    public static function setActive(int $id, bool $active): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $row = self::find($id);
        if (!$row || $row['payroll']) {
            return self::fail('Expense type not found.', 404);
        }
        if ($row['active'] === $active) {
            return ['ok' => true, 'status' => 200, 'message' => $active ? 'Already active.' : 'Already inactive.'];
        }

        if (!$active) {
            if ($row['locked']) {
                return self::fail("{$row['name']} is used by the bike, fuel and workshop records, so it cannot be made inactive.", 422);
            }
            $scopeRcc = $row['rcc'] ?? 'expense';
            $left = array_filter(self::rows(), fn ($r) => $r['id'] !== $row['id'] && $r['active'] && !$r['payroll'] && self::inScope($r, $row['bu'], $scopeRcc));
            if (!$left) {
                return self::fail("“{$row['name']}” is the last active type for this request type — add another one first.", 422);
            }
        } else {
            foreach (self::rows() as $r) {
                if ($r['id'] !== $row['id'] && $r['active'] && $r['bu'] === $row['bu'] && self::lower($r['name']) === self::lower($row['name'])) {
                    return self::fail("Another active type is already called “{$r['name']}”.", 422);
                }
            }
        }

        try {
            $undo = DB::transaction(function () use ($row, $active) {
                DB::table('t_fin_config')->where('id', $row['id'])->update([
                    'is_active'      => $active ? 1 : 0,
                    'hidden_at'      => $active ? null : now(),
                    'hidden_by'      => $active ? null : auth()->id(),
                    'merged_into_id' => $active ? null : $row['merged_into_id'],
                    'updated_at'     => now(),
                ]);
                $undo = ['kind' => $active ? 'restore' : 'hide', 'row' => ['id' => $row['id'], 'name' => $row['name'], 'hidden_at' => $row['hidden_at'], 'merged_into_id' => $row['merged_into_id']]];
                if (!$active) {
                    $undo['bubbles'] = self::rewriteBubbles($row['name'], null, $row['bu']);
                } else {
                    // Turning a type back on puts its quick chip back where it was.
                    $undo['bubbles'] = self::restoreChipSlots($row);
                }
                return $undo;
            });
        } catch (\Throwable $e) {
            return self::fail('Could not save: ' . $e->getMessage(), 500);
        }

        $auditId = self::audit($active ? 'restored' : 'deactivated', $row['id'], $row['name'], $undo, ($active ? 'Turned on “' : 'Turned off “') . $row['name'] . '”');

        return ['ok' => true, 'status' => 200, 'undo_id' => $auditId,
            'message' => $active ? "“{$row['name']}” is turned on again" : "“{$row['name']}” is turned off — it no longer shows in the dropdown"];
    }

    /** Save the display order: ids in the order shown (only expense-type rows are touched). */
    public static function reorder(array $ids): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $valid = array_column(self::rows(), 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => in_array($i, $valid, true))));
        if (!$ids) {
            return self::fail('Nothing to order.', 422);
        }
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                DB::table('t_fin_config')->where('id', $id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });
        self::audit('reordered', null, count($ids) . ' types', ['ids' => $ids], 'Changed expense type order');
        return ['ok' => true, 'status' => 200, 'message' => 'Order saved'];
    }

    public static function setGroup(int $id, ?string $group): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $row = self::find($id);
        if (!$row || $row['payroll']) {
            return self::fail('Expense type not found.', 404);
        }
        $group = self::cleanName((string) $group);
        if (mb_strlen($group) > self::GROUP_MAX) {
            return self::fail('Group name is too long (max ' . self::GROUP_MAX . ' characters).', 422);
        }
        // Re-use the existing spelling of a group typed in another case.
        if ($group !== '') {
            foreach (self::rows() as $r) {
                if ($r['group'] !== null && self::lower($r['group']) === self::lower($group)) {
                    $group = $r['group'];
                    break;
                }
            }
        }
        DB::table('t_fin_config')->where('id', $id)->update(['group_name' => $group === '' ? null : $group, 'updated_at' => now()]);
        self::audit('grouped', $id, $row['name'], ['from' => $row['group'], 'to' => $group ?: null], $group === '' ? "“{$row['name']}” removed from its group" : "“{$row['name']}” moved to group “{$group}”");

        return ['ok' => true, 'status' => 200, 'group' => $group ?: null,
            'message' => $group === '' ? "“{$row['name']}” has no group now" : "“{$row['name']}” is under “{$group}”"];
    }

    // =====================================================================================
    // Undo (rename, merge, inactive, restore) — 24 h, only while nothing newer touched it
    // =====================================================================================

    /**
     * The newest change THIS user can still undo (24 h, not undone, nothing newer on the same
     * type). The drawer and the phone sheet show it as a standing "Last change · Undo" line, so
     * the 24-hour promise does not depend on catching a toast.
     */
    public static function lastUndoable($userId = null): ?array
    {
        $userId = $userId ?: auth()->id();
        if (!$userId || !self::available()) {
            return null;
        }
        try {
            $rows = DB::table('t_sys_audit_log')
                ->where('entity_type', self::AUDIT_ENTITY)
                ->where('user_id', $userId)
                ->whereIn('action', ['renamed', 'merged', 'deactivated', 'restored'])
                ->where('at', '>=', now()->subHours(self::UNDO_HOURS))
                ->orderByDesc('id')->limit(5)->get(['id', 'at', 'entity_id', 'note', 'changes']);
            foreach ($rows as $a) {
                $c = json_decode((string) $a->changes, true);
                if (!is_array($c) || !empty($c['undone'])) {
                    continue;
                }
                $touched = array_filter([$c['row']['id'] ?? null, $c['from']['id'] ?? null, $c['into']['id'] ?? null]);
                $newer = DB::table('t_sys_audit_log')->where('entity_type', self::AUDIT_ENTITY)
                    ->where('id', '>', $a->id)->whereIn('entity_id', $touched ?: [-1])
                    ->whereIn('action', ['renamed', 'merged', 'deactivated', 'restored'])->exists();
                if ($newer) {
                    continue;
                }
                return ['undo_id' => (int) $a->id, 'note' => (string) $a->note, 'at' => (string) $a->at];
            }
        } catch (\Throwable $e) {
            Log::warning('ExpenseTypeService::lastUndoable failed', ['error' => $e->getMessage()]);
        }
        return null;
    }

    public static function undo(int $auditId): array
    {
        if (!self::available()) {
            return self::fail(self::notReadyMessage(), 409);
        }
        $a = DB::table('t_sys_audit_log')->where('id', $auditId)->where('entity_type', self::AUDIT_ENTITY)->first();
        if (!$a || !$a->changes) {
            return self::fail('Nothing to undo.', 404);
        }
        $c = json_decode($a->changes, true);
        if (!is_array($c) || empty($c['kind']) || !empty($c['undone'])) {
            return self::fail('Nothing to undo.', 404);
        }
        if (strtotime((string) $a->at) < time() - self::UNDO_HOURS * 3600) {
            return self::fail('Undo is only possible for ' . self::UNDO_HOURS . ' hours.', 422);
        }
        $touched = array_filter([$c['row']['id'] ?? null, $c['from']['id'] ?? null, $c['into']['id'] ?? null]);
        $newer = DB::table('t_sys_audit_log')->where('entity_type', self::AUDIT_ENTITY)
            ->where('id', '>', $auditId)->whereIn('entity_id', $touched ?: [-1])
            ->whereIn('action', ['renamed', 'merged', 'deactivated', 'restored'])->exists();
        if ($newer) {
            return self::fail('This type was changed again after that — undo the newer change first.', 422);
        }

        try {
            DB::transaction(function () use ($c) {
                switch ($c['kind']) {
                    case 'rename':
                        DB::table('t_fin_config')->where('id', $c['row']['id'])->update([
                            'config_value' => $c['row']['name'],
                            'account_code' => $c['row']['account_code'],
                            'former_names' => $c['row']['former'] ? json_encode($c['row']['former']) : null,
                        ]);
                        self::reverseMove($c, $c['row']['name'], $c['to']);
                        if (!empty($c['account']['code'])) {
                            DB::table('t_fin_accounts')->where('account_code', $c['account']['code'])->update(['account_name' => $c['account']['old_name']]);
                        }
                        break;
                    case 'merge':
                        DB::table('t_fin_config')->where('id', $c['from']['id'])->update([
                            'is_active' => $c['from']['active'] ? 1 : 0,
                            'merged_into_id' => $c['from']['merged_into_id'],
                            'account_code' => $c['from']['account_code'],
                            'hidden_at' => $c['from']['hidden_at'],
                        ]);
                        DB::table('t_fin_config')->where('id', $c['into']['id'])->update([
                            'former_names' => $c['into']['former'] ? json_encode($c['into']['former']) : null,
                            'account_code' => $c['into']['account_code'],
                            'is_active' => $c['into']['active'] ? 1 : 0,
                            'merged_into_id' => $c['into']['merged_into_id'],
                        ]);
                        self::reverseMove($c, $c['from']['name'], $c['into']['name']);
                        break;
                    case 'orphan_merge':
                        DB::table('t_fin_config')->where('id', $c['into']['id'])->update([
                            'former_names' => $c['into']['former'] ? json_encode($c['into']['former']) : null,
                            'account_code' => $c['into']['account_code'],
                        ]);
                        self::reverseMove($c, $c['from'], $c['into']['name']);
                        break;
                    case 'hide':
                        DB::table('t_fin_config')->where('id', $c['row']['id'])->update(['is_active' => 1, 'hidden_at' => null, 'hidden_by' => null, 'merged_into_id' => null]);
                        self::restoreBubbles($c['bubbles'] ?? []);
                        break;
                    case 'restore':
                        DB::table('t_fin_config')->where('id', $c['row']['id'])->update(['is_active' => 0, 'hidden_at' => $c['row']['hidden_at'] ?: now(), 'merged_into_id' => $c['row']['merged_into_id']]);
                        self::restoreBubbles($c['bubbles'] ?? []);
                        break;
                    default:
                        throw new \RuntimeException('Unknown change.');
                }
            });
        } catch (\Throwable $e) {
            Log::error('Expense type undo failed', ['audit_id' => $auditId, 'error' => $e->getMessage()]);
            return self::fail('Undo failed: ' . $e->getMessage(), 500);
        }

        $c['undone'] = true;
        DB::table('t_sys_audit_log')->where('id', $auditId)->update(['changes' => json_encode($c)]);
        self::audit('undone', $a->entity_id, $a->entity_label, ['undid_audit_id' => $auditId], 'Undid: ' . (string) $a->note);

        $out = ['ok' => true, 'status' => 200, 'message' => 'Undone'];
        if ($c['kind'] === 'rename') {
            // A form that had the new name selected follows it back.
            $out['renamed'] = ['from' => $c['to'], 'to' => $c['row']['name']];
        }
        return $out;
    }

    // =====================================================================================
    // Moving a name everywhere it is stored (one place, used by rename + both merges)
    // =====================================================================================

    /**
     * Relabel $from → $to in: past requests (+ their title when the title IS the name),
     * Storage products, quick-chip lists, and the Frozen cost-type map. Returns what it
     * changed so undo can put back exactly those rows.
     */
    private static function moveName(string $from, string $to, array $ownerRow, ?string $nameForFamily): array
    {
        $reqIds = self::relabelQuery($from, $ownerRow, $nameForFamily)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $titleIds = [];
        foreach (array_chunk($reqIds, 500) as $chunk) {
            $titleIds = array_merge($titleIds, DB::table('t_req_master')->whereIn('id', $chunk)->where('title', $from)->pluck('id')->map(fn ($i) => (int) $i)->all());
            DB::table('t_req_master')->whereIn('id', $chunk)->update(['expense_category' => $to]);
        }
        foreach (array_chunk($titleIds, 500) as $chunk) {
            DB::table('t_req_master')->whereIn('id', $chunk)->update(['title' => $to]);
        }

        $supplyIds = [];
        try {
            if (Schema::hasTable('t_fin_supply_product') && Schema::hasColumn('t_fin_supply_product', 'expense_category_name')) {
                $supplyIds = DB::table('t_fin_supply_product')->where('expense_category_name', $from)->pluck('id')->map(fn ($i) => (int) $i)->all();
                if ($supplyIds) {
                    DB::table('t_fin_supply_product')->whereIn('id', $supplyIds)->update(['expense_category_name' => $to]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Expense type rename: supply products not updated', ['error' => $e->getMessage()]);
        }

        return [
            'req_ids'    => $reqIds,
            'title_ids'  => $titleIds,
            'supply_ids' => $supplyIds,
            'bubbles'    => self::rewriteBubbles($from, $to, $ownerRow['bu']),
            'cost_map'   => self::rewriteCostMap($from, $to),
        ];
    }

    private static function reverseMove(array $c, string $from, string $to): void
    {
        foreach (array_chunk($c['req_ids'] ?? [], 500) as $chunk) {
            DB::table('t_req_master')->whereIn('id', $chunk)->where('expense_category', $to)->update(['expense_category' => $from]);
        }
        foreach (array_chunk($c['title_ids'] ?? [], 500) as $chunk) {
            DB::table('t_req_master')->whereIn('id', $chunk)->where('title', $to)->update(['title' => $from]);
        }
        if (!empty($c['supply_ids'])) {
            DB::table('t_fin_supply_product')->whereIn('id', $c['supply_ids'])->where('expense_category_name', $to)->update(['expense_category_name' => $from]);
        }
        self::restoreBubbles($c['bubbles'] ?? []);
        foreach (($c['cost_map'] ?? []) as $op) {
            if ($op['op'] === 'rekey') {
                DB::table('t_fin_cost_type_map')->where('id', $op['id'])->update(['source_key' => $from]);
            } elseif ($op['op'] === 'deleted') {
                $row = $op['row'];
                unset($row['id']);
                DB::table('t_fin_cost_type_map')->insert($row);
            }
        }
    }

    /**
     * Which past requests carry $name for this type: the type's business unit; for a BU-1 type
     * also requests stamped with a unit that has no type of its own by that name.
     */
    private static function relabelQuery(string $name, array $ownerRow, ?string $nameForFamily = null)
    {
        $q = DB::table('t_req_master')->where('expense_category', $name);
        $familyName = self::lower($nameForFamily ?? $name);
        if ($ownerRow['bu'] === 1) {
            $others = [];
            foreach (self::rows() as $r) {
                if ($r['bu'] === 1 || $r['id'] === $ownerRow['id']) {
                    continue;
                }
                $names = array_map([self::class, 'lower'], array_merge([$r['name']], $r['former']));
                if (in_array($familyName, $names, true)) {
                    $others[] = $r['bu'];
                }
            }
            $others = array_values(array_unique($others));
            $q->where(function ($w) use ($others) {
                $w->whereNull('business_unit_id')->orWhereNotIn('business_unit_id', $others ?: [-1]);
            });
        } else {
            $q->where('business_unit_id', $ownerRow['bu']);
        }
        return $q;
    }

    /** Rewrite (or, with $to = null, remove) a name in the quick-chip lists of one business unit. */
    private static function rewriteBubbles(string $from, ?string $to, int $bu): array
    {
        $before = [];
        $rows = DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE\_BUBBLES\_\_%')->get(['config_key', 'config_value']);
        foreach ($rows as $r) {
            if (!preg_match('/__(\d+)$/', $r->config_key, $m) || (int) $m[1] !== $bu) {
                continue;
            }
            $list = json_decode((string) $r->config_value, true);
            if (!is_array($list)) {
                continue;
            }
            $changed = false;
            $out = [];
            foreach ($list as $n) {
                if (is_string($n) && self::lower($n) === self::lower($from)) {
                    $changed = true;
                    if ($to !== null) {
                        $out[] = $to;
                    }
                } else {
                    $out[] = $n;
                }
            }
            if (!$changed) {
                continue;
            }
            $dedup = [];
            foreach ($out as $n) {
                if (!in_array($n, $dedup, true)) {
                    $dedup[] = $n;
                }
            }
            $before[$r->config_key] = $r->config_value;
            DB::table('t_fin_config')->where('config_key', $r->config_key)->update(['config_value' => json_encode(array_values($dedup))]);
            Cache::forget("fin_config_{$r->config_key}");
        }
        return $before;
    }

    /**
     * When a type is turned back on: re-insert its name into each chip list it was taken out
     * of by the last "turn off", at its old position. Returns the lists as they were before
     * (so undoing the turn-on takes the chips out again).
     */
    private static function restoreChipSlots(array $row): array
    {
        // The newest turn-off that actually took a chip away (a second turn-off records none).
        $was = [];
        foreach (DB::table('t_sys_audit_log')->where('entity_type', self::AUDIT_ENTITY)
            ->where('entity_id', $row['id'])->where('action', 'deactivated')
            ->orderByDesc('id')->limit(10)->pluck('changes') as $json) {
            $c = json_decode((string) $json, true);
            if (!empty($c['bubbles']) && is_array($c['bubbles'])) {
                $was = $c['bubbles'];
                break;
            }
        }
        $before = [];
        foreach ($was as $key => $oldJson) {
            $old = json_decode((string) $oldJson, true);
            $pos = is_array($old) ? array_search($row['name'], $old, true) : false;
            if ($pos === false) {
                continue;
            }
            $curJson = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            $cur = json_decode((string) $curJson, true);
            if (!is_array($cur) || in_array($row['name'], $cur, true)) {
                continue;
            }
            $before[$key] = $curJson;
            array_splice($cur, min((int) $pos, count($cur)), 0, [$row['name']]);
            DB::table('t_fin_config')->where('config_key', $key)->update(['config_value' => json_encode(array_values($cur))]);
            Cache::forget("fin_config_{$key}");
        }
        return $before;
    }

    private static function restoreBubbles(array $before): void
    {
        foreach ($before as $key => $json) {
            DB::table('t_fin_config')->where('config_key', $key)->update(['config_value' => $json]);
            Cache::forget("fin_config_{$key}");
        }
    }

    /** Frozen Month Review cost-type tags are keyed by name: carry the tag over. */
    private static function rewriteCostMap(string $from, string $to): array
    {
        $ops = [];
        try {
            if (!Schema::hasTable('t_fin_cost_type_map')) {
                return [];
            }
            $rows = DB::table('t_fin_cost_type_map')->where('source_kind', 'expense_category')->where('source_key', $from)->get();
            foreach ($rows as $r) {
                $clash = DB::table('t_fin_cost_type_map')->where('source_kind', 'expense_category')
                    ->where('business_unit_id', $r->business_unit_id)->where('source_key', $to)->where('id', '!=', $r->id)->exists();
                if ($clash) {
                    // The target already has its own tag — it keeps it.
                    $ops[] = ['op' => 'deleted', 'row' => (array) $r];
                    DB::table('t_fin_cost_type_map')->where('id', $r->id)->delete();
                } else {
                    $ops[] = ['op' => 'rekey', 'id' => (int) $r->id];
                    DB::table('t_fin_cost_type_map')->where('id', $r->id)->update(['source_key' => $to]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Expense type rename: cost map not updated', ['error' => $e->getMessage()]);
        }
        return $ops;
    }

    /**
     * The ledger account keeps its CODE (that is the point of the pin). Its display name
     * follows the rename only when it is plainly this type's own account.
     */
    private static function renameAccountLabel(string $code, string $old, string $new, int $rowId): array
    {
        $acc = DB::table('t_fin_accounts')->where('account_code', $code)->first(['id', 'account_name']);
        if (!$acc || $acc->account_name !== 'Expense - ' . $old) {
            return [];
        }
        $shared = DB::table('t_fin_config')->where('config_key', 'LIKE', 'EXPENSE\_CATEGORY\_%')
            ->where('id', '!=', $rowId)->where('account_code', $code)->exists();
        if ($shared) {
            return [];
        }
        DB::table('t_fin_accounts')->where('id', $acc->id)->update(['account_name' => 'Expense - ' . $new]);
        return ['code' => $code, 'old_name' => $acc->account_name];
    }

    // =====================================================================================
    // Small helpers
    // =====================================================================================

    public static function lower(?string $s): string
    {
        return mb_strtolower(trim((string) $s));
    }

    public static function cleanName(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function addFormer(array $former, array $add): array
    {
        $out = $former;
        foreach ($add as $n) {
            if (!is_string($n) || trim($n) === '') {
                continue;
            }
            $exists = false;
            foreach ($out as $f) {
                if (self::lower($f) === self::lower($n)) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $out[] = $n;
            }
        }
        return array_values($out);
    }

    private static function fail(string $message, int $status): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message];
    }

    public static function notReadyMessage(): string
    {
        return 'Expense type editing is not switched on yet — the database update (expense_types_manage_oct2026.sql) has not been run.';
    }

    /** One audit row; returns its id (the undo handle). Never throws. */
    private static function audit(string $action, ?int $id, ?string $label, ?array $changes, ?string $note): ?int
    {
        try {
            if (!Schema::hasTable('t_sys_audit_log')) {
                return null;
            }
            $req = request();
            $source = app()->runningInConsole() ? 'system' : (($req && $req->is('api/*')) ? 'mobile' : 'web');
            return (int) DB::table('t_sys_audit_log')->insertGetId([
                'at'           => now(),
                'user_id'      => auth()->id(),
                'source'       => $source,
                'action'       => substr($action, 0, 40),
                'entity_type'  => self::AUDIT_ENTITY,
                'entity_id'    => $id,
                'entity_label' => $label !== null ? mb_substr($label, 0, 80) : null,
                'changes'      => $changes ? json_encode($changes) : null,
                'note'         => $note !== null ? mb_substr($note, 0, 255) : null,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Expense type audit failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
