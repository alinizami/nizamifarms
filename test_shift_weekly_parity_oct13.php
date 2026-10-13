<?php
/**
 * 🔁 WEEKLY DAYS — PARITY (Oct-13 2026). Two promises, proven on the whole replica:
 *
 *   A. For every person WITHOUT weekly days the new resolver returns byte-for-byte what the
 *      OLD one did, on every date (the old class is loaded from the pre-change backup under
 *      another name). Every key the old one returned is compared.
 *   B. The PART 3 clean-up in shift_weekly_days_oct2026.sql (delete rows that end before they
 *      start · close superseded open rows) changes NO answer for anyone on any date.
 *      Run inside a transaction and rolled back.
 *
 * Run:  php test_shift_weekly_parity_oct13.php "<path to the pre-change ShiftResolutionService.php>"
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

config(['cache.default' => 'array']);

$oldPath = $argv[1] ?? null;
if (!$oldPath || !is_file($oldPath)) { echo "pass the pre-change ShiftResolutionService.php\n"; exit(2); }
$src = file_get_contents($oldPath);
$src = str_replace('namespace App\Services;', 'namespace ParityOld;', $src);
$src = preg_replace('/^use /m', 'use ', $src);
$src = str_replace(['use App\Models\Ops\\', 'class ShiftResolutionService'], ['use App\Models\Ops\\', 'class OldResolver'], $src);
$tmp = sys_get_temp_dir() . '/parity_old_resolver_' . getmypid() . '.php';
file_put_contents($tmp, $src);
require $tmp;

$from = '2025-10-01'; $to = '2026-12-31';
$users = DB::table('t_ops_user_shift_assignment')->distinct()->pluck('user_id')->map(fn ($x) => (int) $x)->all();
$users = array_values(array_unique(array_merge($users,
    DB::table('t_sys_user')->where('is_active', 1)->pluck('id')->map(fn ($x) => (int) $x)->all())));
$dates = [];
for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $dates[] = $d;
echo "people: " . count($users) . " · dates: " . count($dates) . " ({$from} … {$to})\n";

function snapshot($svc, array $users, array $dates, array $keys = null): array {
    $out = [];
    foreach ($users as $u) {
        foreach ($dates as $d) {
            $r = $svc->getUserShift($u, $d);
            if ($keys) $r = array_intersect_key($r, array_flip($keys));
            $out["$u|$d"] = $r;
        }
    }
    return $out;
}
function reset_memos() {
    (new \App\Services\ShiftResolutionService())->clearAllShiftCaches();
    $rp = new ReflectionClass(\ParityOld\OldResolver::class);
    foreach (['shiftMemo', 'hireMemo', 'tagMemo'] as $p) {
        if ($rp->hasProperty($p)) { $x = $rp->getProperty($p); $x->setAccessible(true); $x->setValue(null, []); }
    }
}

// ── A ──────────────────────────────────────────────────────────────────────────
$withWeekly = DB::table('t_ops_user_shift_assignment')->whereNotNull('day_pattern')->count();
echo "rows with weekly days on the replica: {$withWeekly} (must be 0 for A to be meaningful)\n";
reset_memos();
$old = snapshot(new \ParityOld\OldResolver(), $users, $dates);
reset_memos();
$oldKeys = array_keys(reset($old));
$new = snapshot(new \App\Services\ShiftResolutionService(), $users, $dates, $oldKeys);
$diffA = 0; $shown = 0;
foreach ($old as $k => $v) {
    if ($new[$k] !== $v) { $diffA++; if ($shown++ < 5) echo "  A diff $k\n    old " . json_encode($v) . "\n    new " . json_encode($new[$k]) . "\n"; }
}
echo ($diffA ? '✗' : '✓') . " A. old vs new resolver: " . count($old) . " person-days compared, {$diffA} differ\n";

// ── B ──────────────────────────────────────────────────────────────────────────
$sql = file_get_contents(__DIR__ . '/database/migrations/shift_weekly_days_oct2026.sql');
preg_match('/-- 3a\s*\n(DELETE FROM.+?;)/s', $sql, $mA);
preg_match('/-- 3b\s*\n(UPDATE .+?;)/s', $sql, $mB);
if (empty($mA[1]) || empty($mB[1])) { echo "could not find PART 3 statements\n"; exit(2); }
reset_memos();
$before = snapshot(new \App\Services\ShiftResolutionService(), $users, $dates);
DB::beginTransaction();
try {
    $nA = DB::delete(rtrim($mA[1], ';'));
    $nB = DB::update(rtrim($mB[1], ';'));
    echo "clean-up inside a transaction: deleted {$nA}, closed {$nB}\n";
    reset_memos();
    $after = snapshot(new \App\Services\ShiftResolutionService(), $users, $dates);
    $openDupes = DB::select("SELECT COUNT(*) c FROM (SELECT user_id FROM t_ops_user_shift_assignment WHERE effective_to IS NULL GROUP BY user_id HAVING COUNT(*) > 1) t")[0]->c;
    $inverted = DB::table('t_ops_user_shift_assignment')->whereNotNull('effective_from')->whereNotNull('effective_to')->whereColumn('effective_to', '<', 'effective_from')->count();
} finally {
    DB::rollBack();
}
$diffB = 0; $shown = 0;
foreach ($before as $k => $v) {
    if ($after[$k] !== $v) { $diffB++; if ($shown++ < 5) echo "  B diff $k\n    before " . json_encode($v) . "\n    after  " . json_encode($after[$k]) . "\n"; }
}
echo ($diffB ? '✗' : '✓') . " B. clean-up changes no answer: " . count($before) . " person-days, {$diffB} differ · after it: {$openDupes} people with >1 open row, {$inverted} inverted rows\n";
@unlink($tmp);
$okAll = !$diffA && !$diffB && (int) $openDupes === 0 && (int) $inverted === 0;
echo ($okAll ? "✅ parity holds" : "❌ parity broken") . "\n";
exit($okAll ? 0 : 1);
