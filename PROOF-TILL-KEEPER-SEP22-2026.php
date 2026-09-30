<?php
/**
 * PROOF — Till keeper (Sep-22-2026): entered_by, the "whose hand" rule, the Hub filter,
 * the count checkpoint + drift, and the cash pill's watermark.
 *
 * Runs inside ONE transaction which is ROLLED BACK at the end — it writes counts and ledger
 * rows against the real replica and leaves nothing behind.
 *
 * ⚠ Local only. Refuses to run against anything but a local database.
 *
 *   php PROOF-TILL-KEEPER-SEP22-2026.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\FIN\AccountModel;
use App\Models\FIN\CashCountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\LedgerWatchModel;
use App\Services\FIN\BalancePostingService;
use App\Services\FIN\LedgerWatchService;
use App\Services\FIN\TillCountService;
use Illuminate\Support\Facades\DB;

if (!in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "REFUSING: not a local database\n");
    exit(1);
}
// ⚠ The replica renders TIMESTAMP columns 2h ahead of the DATETIME ones it is compared
// against. Prod is one clock and needs nothing; here the drift maths would be 2h out.
DB::statement("SET SESSION time_zone='+01:00'");

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false): void {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; return; }
    $fail++;
    echo "  ✗ $what\n";
    if (!$raw) {
        echo "      got:  " . var_export($got, true) . "\n";
        echo "      want: " . var_export($want, true) . "\n";
    }
}
function section(string $t): void { echo "\n== $t ==\n"; }

/**
 * ⚠ The default guard here is `api` (a RequestGuard), which has no loginUsingId/logout.
 * setUser/forgetUser exist on every guard and are what LedgerModel::creating reads through
 * auth()->id(), so this drives the real code path rather than a test-only one.
 */
// ⚠ App\Models\User is the Authenticatable one; SysAdmin\UserModel is the plain domain model
// on the same t_sys_user table and is NOT accepted by a guard.
function asUser(int $id): void {
    auth()->setUser(\App\Models\User::findOrFail($id));
}
function asNobody(): void { auth()->forgetUser(); }

const SHABIB = 79;   // keeper of NF_CASH
const TAIMUR = 68;
const HAIDER = 71;

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
section('1. F0 — entered_by is stamped, and created_by really is a different person');

$acct = AccountModel::where('account_code', 'NF_CASH')->firstOrFail();
ok('NF_CASH resolves', (int) $acct->id > 0, true, true);

asUser(TAIMUR);
$row = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF row',
    'from_account_id'  => $acct->id,
    'to_account_id'    => 22,
    'amount'           => 111.00,
    'approval_status'  => 'approved',
    // The requester, exactly as LedgerPostingService sets it for an expense.
    'created_by'       => HAIDER,
]);
ok('creating hook stamped the logged-in actor', (int) $row->entered_by, TAIMUR);
ok('created_by kept the requester', (int) $row->created_by, HAIDER);
ok('actorId() prefers entered_by', $row->actorId(), TAIMUR);
ok('actorName() is the actor, not the requester', $row->actorName(), 'Taimur');

// Console/no-session must not explode, and must not invent an actor.
asNobody();
$sysRow = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF system row',
    'from_account_id'  => $acct->id,
    'to_account_id'    => 22,
    'amount'           => 1.00,
    'approval_status'  => 'approved',
]);
ok('no session → entered_by stays null (no crash)', $sysRow->entered_by, null);
ok('a row with no actor reads as System', $sysRow->actorName(), 'System');

// ─────────────────────────────────────────────────────────────────────────────
section('2. The ONE "whose hand" rule — all six cases (owner ruling: entered OR approved)');

ok('Taimur entered it → not his own hand? no', $row->isSomeoneElsesHand(TAIMUR), false);
ok('…but it IS somebody else to Shabib', $row->isSomeoneElsesHand(SHABIB), true);
ok('…and to Haider, whose expense it is', $row->isSomeoneElsesHand(HAIDER), true);
ok('a no-actor row is somebody else to everyone', $sysRow->isSomeoneElsesHand(SHABIB), true);

// ⭐ The ruling that stops the pill filling with noise: a row Shabib APPROVED is his hand.
$row->approved_by = SHABIB;
$row->save();
ok('APPROVED by Shabib → now his own hand', $row->isSomeoneElsesHand(SHABIB), false);
ok('…still somebody else to Haider', $row->isSomeoneElsesHand(HAIDER), true);

// Real prod-shaped data, not just the row we made.
$real = LedgerModel::find(22170);              // rider deposit, approved by Shabib
if ($real) {
    ok('real #22170 (Haider typed, Shabib approved) is NOT Shabib\'s "others"',
        $real->isSomeoneElsesHand(SHABIB), false);
    ok('real #22170 IS Taimur\'s "others"', $real->isSomeoneElsesHand(TAIMUR), true);
}
$real2 = LedgerModel::find(22190);             // Taimur's auto-approved mobile vendor payment
if ($real2) {
    ok('real #22190 (the Rs 150k-shaped case) IS Shabib\'s "others"',
        $real2->isSomeoneElsesHand(SHABIB), true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. effectOnAccount agrees with the engine that actually moved the money');

// The only safe test of a sign convention is to post a row and watch the stored balance.
$before = (float) AccountModel::find($acct->id)->current_balance;
$mv = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF movement',
    'from_account_id'  => $acct->id,
    'to_account_id'    => 22,
    'amount'           => 250.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($mv);
$after = (float) AccountModel::find($acct->id)->current_balance;
ok('engine moved the balance by exactly effectOnAccount()',
    round($after - $before, 2), round($mv->effectOnAccount((int) $acct->id), 2));
ok('…and that is negative for money leaving the till',
    $mv->effectOnAccount((int) $acct->id) < 0, true, true);

// vendor_purchase is stored with its sides reversed — the one exception in the engine.
$vp = new LedgerModel(['transaction_type' => LedgerModel::TYPE_VENDOR_PURCHASE,
    'from_account_id' => $acct->id, 'to_account_id' => 999, 'amount' => 500]);
ok('vendor_purchase FROM this account is a PLUS (sides reversed)',
    $vp->effectOnAccount((int) $acct->id), 500.0);

// ─────────────────────────────────────────────────────────────────────────────
section('4. daysBackdated — the chip that must never cry wolf');

$bd = new LedgerModel(['transaction_date' => '2026-08-22']);
$bd->created_at = \Carbon\Carbon::parse('2026-09-17 12:22:47');
ok('26-day-old petrol claim reads 26', $bd->daysBackdated(), 26);
$sd = new LedgerModel(['transaction_date' => now()->toDateString()]);
$sd->created_at = now();
ok('a same-day row reads 0', $sd->daysBackdated(), 0);
$fd = new LedgerModel(['transaction_date' => now()->addDays(3)->toDateString()]);
$fd->created_at = now();
ok('a FORWARD-dated row is not "backdated"', $fd->daysBackdated(), 0);

// ─────────────────────────────────────────────────────────────────────────────
section('5. The count — keeper gating and the seal');

$tills = app(TillCountService::class);
ok('Shabib is the keeper of NF_CASH', $tills->isKeeper(SHABIB, (int) $acct->id), true);
ok('Taimur is NOT (he can pay from it, but does not hold it)',
    $tills->isKeeper(TAIMUR, (int) $acct->id), false);
ok('keeperAccounts(Shabib) finds exactly the till', $tills->keeperAccounts(SHABIB)->count() >= 1, true, true);
ok('keeperAccounts(Taimur) is empty', $tills->keeperAccounts(TAIMUR)->count(), 0);

$ctx = $tills->askContext(SHABIB);
ok('askContext names the account', $ctx['account_code'] ?? null, 'NF_CASH');
ok('askContext carries the books\' figure', isset($ctx['system_balance']), true);
ok('askContext carries NO pre-filled answer', array_key_exists('counted_amount', $ctx ?? []), false);
ok('askContext(Taimur) is null', $tills->askContext(TAIMUR), null);

$sysNow = round((float) AccountModel::find($acct->id)->current_balance, 2);
$count1 = $tills->record($acct, SHABIB, $sysNow, CashCountModel::SOURCE_CHECKOUT, null, 'proof count');
ok('a matching count records difference 0.00', (float) $count1->difference, 0.0);
ok('…and matches()', $count1->matches(), true);
ok('the seal is the newest ledger id at that instant',
    (int) $count1->last_ledger_id, (int) LedgerModel::max('id'));
ok('counting moved NO money',
    round((float) AccountModel::find($acct->id)->current_balance, 2), $sysNow);

$short = $tills->record($acct, SHABIB, $sysNow - 2000, CashCountModel::SOURCE_HUB);
ok('a short count records a negative difference', (float) $short->difference, -2000.0);
ok('shortBy() reads positive when he is short', $short->shortBy(), 2000.0);
ok('…and does not match', $short->matches(), false);

// ─────────────────────────────────────────────────────────────────────────────
section('6. ⭐⭐ DRIFT — the backdated row that lands behind a count is named on its line');

// Re-seal, then post the incident-shaped row: entered today, DATED before the count.
$anchor = $tills->record($acct, SHABIB, round((float) AccountModel::find($acct->id)->current_balance, 2));
asUser(TAIMUR);
$late = LedgerModel::create([
    'transaction_date' => now()->subDays(3)->toDateString(),   // ← dated BEFORE the count
    'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
    'description'      => 'PROOF backdated vendor payment',
    'from_account_id'  => $acct->id,
    'to_account_id'    => 62,
    'amount'           => 150000.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($late);

$rows = $tills->withDrift((int) $acct->id, now()->subDays(30));
$line = collect($rows)->firstWhere('id', (int) $anchor->id);
ok('the anchor count is found', is_array($line), true, true);
ok('it reports exactly 1 late entry', $line['late_count'] ?? null, 1);
ok('…names the row', in_array((int) $late->id, $line['late_ids'] ?? [], true), true);
ok('…and its net effect on the till', $line['late_net'] ?? null, -150000.0);

// A FORWARD-dated row that arrives later is not rewriting history.
$fwd = LedgerModel::create([
    'transaction_date' => now()->addDays(2)->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF forward dated',
    'from_account_id'  => $acct->id, 'to_account_id' => 22, 'amount' => 77.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($fwd);
$line2 = collect($tills->withDrift((int) $acct->id, now()->subDays(30)))->firstWhere('id', (int) $anchor->id);
ok('a forward-dated arrival is NOT reported as drift', $line2['late_count'] ?? null, 1);

// A LATER count re-seals: the same late row must not be reported on both lines.
$after2 = $tills->record($acct, SHABIB, round((float) AccountModel::find($acct->id)->current_balance, 2));
$rows3 = $tills->withDrift((int) $acct->id, now()->subDays(30));
$newest = collect($rows3)->firstWhere('id', (int) $after2->id);
ok('the newest count starts clean', $newest['late_count'] ?? null, 0);
$old = collect($rows3)->firstWhere('id', (int) $anchor->id);
ok('…and the earlier count still owns its own drift', $old['late_count'] ?? null, 1);

// A row that existed at the seal and is CHANGED afterwards.
$touchTarget = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF to be edited',
    'from_account_id'  => $acct->id, 'to_account_id' => 22, 'amount' => 900.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($touchTarget);
$sealed = $tills->record($acct, SHABIB, round((float) AccountModel::find($acct->id)->current_balance, 2));
sleep(1);                                  // updated_at must land strictly after counted_at
// ⚠ Edited through the engine's documented bracket (reverse → mutate → apply), which is how a
// real correction happens. Mutating the amount directly would leave the stored balance on the
// OLD figure and break the reconciliation in §8 — a test-only bug that looks like a real one.
$poster = app(BalancePostingService::class);
$poster->reverse($touchTarget);
$touchTarget->amount = 950.00;
$touchTarget->save();
$poster->apply($touchTarget);
$line4 = collect($tills->withDrift((int) $acct->id, now()->subDays(30)))->firstWhere('id', (int) $sealed->id);
ok('an entry changed after the count is reported', $line4['touched_count'] ?? null, 1);
ok('…by id', in_array((int) $touchTarget->id, $line4['touched_ids'] ?? [], true), true);

/**
 * ⚠⚠ REGRESSION — the first implementation asked `updated_at > counted_at` and reported
 * **1,665** "changed" entries against one count on the replica, because updated_at moves for
 * reasons that are not edits. `touched` now reads the audit log. A count on a quiet account
 * must report ZERO, and the whole page's touched lists must stay small enough to read.
 */
$quiet = $tills->record($acct, SHABIB, round((float) AccountModel::find($acct->id)->current_balance, 2));
$quietLine = collect($tills->withDrift((int) $acct->id, now()->subDays(30)))->firstWhere('id', (int) $quiet->id);
ok('a count on a quiet moment reports NO phantom edits', $quietLine['touched_count'] ?? null, 0);
$worst = collect($tills->withDrift((int) $acct->id, now()->subDays(60)))
    ->map(fn ($r) => count($r['touched_ids']))->max();
ok('no checkpoint line ever names more than 8 ids', $worst <= 8, true, true);

// A DELETED row still has to be attributable — it is gone from t_fin_ledger entirely.
$doomed = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF to be deleted',
    'from_account_id'  => $acct->id, 'to_account_id' => 22, 'amount' => 400.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($doomed);
$beforeDel = $tills->record($acct, SHABIB, round((float) AccountModel::find($acct->id)->current_balance, 2));
$doomedId = (int) $doomed->id;
sleep(1);
$poster->reverse($doomed);          // un-post it first, or the balance keeps the money
$doomed->delete();
ok('the row really is gone from the ledger', LedgerModel::find($doomedId), null);
$delLine = collect($tills->withDrift((int) $acct->id, now()->subDays(30)))->firstWhere('id', (int) $beforeDel->id);
ok('…but the count still reports it was deleted', $delLine['touched_deleted'] ?? null, 1);
ok('…and names it', in_array($doomedId, $delLine['touched_ids'] ?? [], true), true);

// ─────────────────────────────────────────────────────────────────────────────
section('7. The cash pill');

$watch = app(LedgerWatchService::class);
$ids = $watch->watchedAccountIds(SHABIB);
ok('Shabib watches NF_CASH', in_array((int) $acct->id, $ids, true), true);
// ⭐⭐ Owner's screenshot (as Taimur): tagged on 7 accounts, the pill showed Qasim's NF Food
// payments, Shabib's petrol postings and rider settlements Shabib had approved — clutter. The
// pill watches the tills you KEEP, not the accounts you may spend from.
ok('…and ONLY that — not the 5 other accounts he is tagged on', count($ids), 1);
/**
 * ⭐ Owner: "isn't Taimur holding the online account?" He is — default on it, 624 of its rows in
 * 60 days. A BANK keeper is watched but never counted: watching ≠ counting.
 */
$onlineAcct = AccountModel::where('account_code', 'ONLINE')->firstOrFail();
$tIds = $watch->watchedAccountIds(TAIMUR);
ok('Taimur (tagged on 7 accounts) watches exactly ONLINE, the one he keeps', $tIds, [(int) $onlineAcct->id]);
ok('…but is never asked to COUNT it (a bank has no drawer)', $tills->keeperAccounts(TAIMUR)->count(), 0);
ok('…so check-out offers him no till sheet', $tills->askContext(TAIMUR), null);
asUser(SHABIB);
$xfer = LedgerModel::create([
    'transaction_date' => now()->toDateString(), 'transaction_type' => LedgerModel::TYPE_TRANSFER,
    'description' => 'PROOF Shabib moves money out of ONLINE', 'from_account_id' => $onlineAcct->id,
    'to_account_id' => $acct->id, 'amount' => 49020.00, 'approval_status' => 'approved',
]);
app(BalancePostingService::class)->apply($xfer);
ok('Shabib\'s transfer out of ONLINE rings Taimur\'s bell',
    $watch->unread(TAIMUR)->contains(fn ($r) => (int) $r->id === (int) $xfer->id), true);
ok('…and, landing in NF Cash, it does NOT ring Shabib\'s (his own hand)',
    $watch->unread(SHABIB)->contains(fn ($r) => (int) $r->id === (int) $xfer->id), false);
// A rider's online settlement L1-approved by Taimur is his own hand; one approved by Shabib is
// order-flow and stays out of the pill either way.
asUser(HAIDER);
$onlineInv = LedgerModel::create([
    'transaction_date' => now()->toDateString(), 'transaction_type' => LedgerModel::TYPE_INVOICE,
    'description' => 'PROOF rider online delivery', 'from_account_id' => 6,
    'to_account_id' => $onlineAcct->id, 'amount' => 2500.00, 'approval_status' => 'approved', 'approved_by' => SHABIB,
]);
app(BalancePostingService::class)->apply($onlineInv);
ok('an online delivery on his account still does not ring Taimur\'s bell (order pipeline)',
    $watch->unread(TAIMUR)->contains(fn ($r) => (int) $r->id === (int) $onlineInv->id), false);
$food = AccountModel::where('account_code', 'NF_FOOD')->first();
if ($food) {
    asUser(DB::table('t_sys_user')->where('fullname', 'like', 'Qasim%')->value('id') ?: TAIMUR);
    $qasim = LedgerModel::create([
        'transaction_date' => now()->toDateString(), 'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
        'description' => 'PROOF Qasim pays a vendor from NF Food', 'from_account_id' => $food->id,
        'to_account_id' => 62, 'amount' => 3195.00, 'approval_status' => 'approved',
    ]);
    app(BalancePostingService::class)->apply($qasim);
    ok('Qasim\'s NF Food payment never reaches Shabib\'s pill',
        $watch->unread(SHABIB)->contains(fn ($r) => (int) $r->id === (int) $qasim->id), false);
}

LedgerWatchModel::where('user_id', SHABIB)->delete();
$n0 = $watch->unreadCount(SHABIB);
ok('with no watermark, the backdated Rs 150k payment is unread', $n0 > 0, true, true);
$list = $watch->recent(SHABIB);
// C11: with no watermark the unread floor is "the last 7 × 24 h", so the block may reach one
// day further back (to the oldest unread day) — never more.
ok('the drawer shows only the last 7 typed days (+ the day of the oldest unread row)',
    $list['from'] >= now()->subDays(7)->toDateString()
    && collect($list['items'])->every(fn ($i) => $i['day'] >= $list['from']), true, true);
$first = collect($list['items'])->firstWhere('id', (int) $late->id);
ok('…and the incident row is in it', is_array($first), true, true);
ok('…attributed to Taimur', $first['who'] ?? null, 'Taimur');
ok('…with its effect on the till', $first['effect'] ?? null, -150000.0);
ok('…flagged as backdated', ($first['days_backdated'] ?? 0) > 0, true, true);
ok('…and marked unread', $first['unread'] ?? null, true);

$watch->markSeen(SHABIB, $list['latest_id']);
ok('after viewing, the count is 0', $watch->unreadCount(SHABIB), 0);
ok('the list still shows them (a notification you can re-read)',
    count($watch->recent(SHABIB)['items']) > 0, true, true);

// ⚠ Two tabs must not resurrect rows he has already cleared.
$high = $watch->watermark(SHABIB);
$watch->markSeen(SHABIB, 1);
ok('the watermark never walks backwards', $watch->watermark(SHABIB), $high);

/**
 * ⭐ C11 (pre-deploy, Sep-29): the badge counts an unread row however old it is, but the first
 * page used to show 7 days only — and opening marks everything read. A row typed 10 days ago
 * was counted, never shown, then cleared. It must be ON the first page, and "Show earlier
 * days" must keep the dots the first page drew (the drawer passes `seen_since` back).
 */
asUser(TAIMUR);
$watch->markSeen(SHABIB);
$pre = $watch->watermark(SHABIB);
$old = LedgerModel::create([
    'transaction_date' => now()->subDays(10)->toDateString(),
    'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
    'description'      => 'PROOF C11 unread for 10 days',
    'from_account_id'  => $acct->id, 'to_account_id' => 62, 'amount' => 777.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($old);
$tenAgo = now()->subDays(10)->setTime(11, 0);
DB::table('t_fin_ledger')->where('id', $old->id)->update(['created_at' => $tenAgo->format('Y-m-d H:i:s')]);
ok('C11: a row typed 10 days ago, unread, is counted by the badge',
    $watch->unread(SHABIB)->contains(fn ($r) => (int) $r->id === (int) $old->id), true);
$p1 = $watch->recent(SHABIB);
$row = collect($p1['items'])->firstWhere('id', (int) $old->id);
ok('…and is on the FIRST page', is_array($row), true, true);
ok('…with its unread dot', $row['unread'] ?? null, true);
ok('…the block reaches back to its day', $p1['from'], $tenAgo->toDateString());
ok('…whole days, newest first', collect($p1['items'])->pluck('day')->all(),
    collect($p1['items'])->pluck('day')->sortDesc()->values()->all());
ok('…and the page says which watermark the dots were drawn against', $p1['seen_since'], $pre);
$watch->markSeen(SHABIB, $p1['latest_id']);        // what open() does next
$p1b = $watch->recent(SHABIB);
ok('once read, the first page is 7 days again', $p1b['from'], now()->subDays(6)->toDateString());
$before = $p1b['next_before']; $pg = null; $hit = null; $pageBefore = null;
for ($n = 0; $before && !$hit && $n < 12; $n++) {
    $pageBefore = $before;
    $pg = $watch->recent(SHABIB, $before, $p1['seen_since']);
    $hit = collect($pg['items'])->firstWhere('id', (int) $old->id);
    $before = $pg['next_before'];
}
ok('C11: "Show earlier days" with since= keeps the row\'s unread dot', $hit['unread'] ?? null, true);
ok('…without since= it reads as read (the open moved the watermark)',
    collect($watch->recent(SHABIB, $pageBefore)['items'])->firstWhere('id', (int) $old->id)['unread'] ?? null, false);
ok('…a since= ABOVE the real watermark is ignored (display only, cannot invent dots)',
    collect($watch->recent(SHABIB, $pageBefore, $watch->watermark(SHABIB) + 100000)['items'])
        ->firstWhere('id', (int) $old->id)['unread'] ?? null, false);
$ctl = app(\App\Http\Controllers\FIN\Hub\HubController::class);
asUser(SHABIB);
$viaHttp = $ctl->watchList(\Illuminate\Http\Request::create('/finance/hub/watch/list', 'GET',
    ['before' => $pageBefore, 'since' => (string) $p1['seen_since']]))->getData(true);
ok('…the endpoint passes ?since= through',
    collect($viaHttp['items'] ?? [])->firstWhere('id', (int) $old->id)['unread'] ?? null, true);
$junk = $ctl->watchList(\Illuminate\Http\Request::create('/finance/hub/watch/list', 'GET',
    ['before' => $pageBefore, 'since' => '12abc']))->getData(true);
ok('…and treats a non-number since= as "the current watermark"',
    collect($junk['items'] ?? [])->firstWhere('id', (int) $old->id)['unread'] ?? null, false);
// the cap: an unread row a month and more back is not dragged onto the first page
LedgerWatchModel::where('user_id', SHABIB)->update(['last_seen_ledger_id' => $pre]);
DB::table('t_fin_ledger')->where('id', $old->id)->update(['created_at' => now()->subDays(40)->setTime(11, 0)->format('Y-m-d H:i:s')]);
$capped = $watch->recent(SHABIB);
ok('C11 cap: an unread row 40 days back does not stretch the first page past 31 days',
    [$capped['from'] >= now()->subDays(30)->toDateString(), collect($capped['items'])->contains('id', (int) $old->id)], [true, false]);
$watch->markSeen(SHABIB);

// His OWN entry must never appear in his own pill.
asUser(SHABIB);
$mine = LedgerModel::create([
    'transaction_date' => now()->toDateString(),
    'transaction_type' => LedgerModel::TYPE_EXPENSE,
    'description'      => 'PROOF Shabib own entry',
    'from_account_id'  => $acct->id, 'to_account_id' => 22, 'amount' => 30.00,
    'approval_status'  => 'approved',
]);
app(BalancePostingService::class)->apply($mine);
ok('his own new entry does not notify him', $watch->unreadCount(SHABIB), 0);
// If Taimur were ALSO keeper of this till (a tick on the account page), Shabib's hand is somebody
// else's to him — proves the rule is per-viewer, not "Shabib is special".
DB::table('t_fin_account_users')->where('account_id', $acct->id)->where('user_id', TAIMUR)->update(['is_keeper' => 1]);
ok('…but it would notify a second keeper of the same till',
    $watch->unread(TAIMUR)->contains(fn ($r) => (int) $r->id === (int) $mine->id), true);
DB::table('t_fin_account_users')->where('account_id', $acct->id)->where('user_id', TAIMUR)->update(['is_keeper' => 0]);

/**
 * ⭐⭐ THE ORDER PIPELINE MUST NOT RING THE BELL. Before ORDER_FLOW_TYPES existed, 280 of the
 * 420 rows in Shabib's 14-day "not my hand" set were rider deliveries L1-approved by Taimur on
 * Online Approvals. A vendor payment by the same hand on the same day must still get through.
 */
asUser(TAIMUR);
$online = AccountModel::where('account_code', 'ONLINE')->first();
if ($online) {
    $delivery = LedgerModel::create([
        'transaction_date' => now()->toDateString(),
        'transaction_type' => LedgerModel::TYPE_INVOICE,
        'description'      => 'PROOF online delivery, L1 by Taimur',
        'from_account_id'  => 6, 'to_account_id' => $online->id, 'amount' => 3200.00,
        'approval_status'  => 'approved', 'approved_by' => TAIMUR,
    ]);
    app(BalancePostingService::class)->apply($delivery);
    $vendorPay = LedgerModel::create([
        'transaction_date' => now()->toDateString(),
        'transaction_type' => LedgerModel::TYPE_VENDOR_PAYMENT,
        'description'      => 'PROOF vendor payment by Taimur',
        'from_account_id'  => $acct->id, 'to_account_id' => 62, 'amount' => 5000.00,
        'approval_status'  => 'approved', 'approved_by' => TAIMUR,
    ]);
    app(BalancePostingService::class)->apply($vendorPay);
    $ids = $watch->unread(SHABIB)->pluck('id')->map(fn ($v) => (int) $v);
    ok('an online delivery Taimur approved does NOT ring Shabib\'s bell', $ids->contains((int) $delivery->id), false);
    ok('…but his vendor payment from the till DOES', $ids->contains((int) $vendorPay->id), true);
    ok('the Hub filter still lists the delivery (a ledger page is complete)',
        $delivery->isSomeoneElsesHand(SHABIB), true);
}

// Someone tagged on nothing gets nothing — the self-hiding contract.
$nobody = DB::table('t_sys_user')->whereNotIn('id',
    DB::table('t_fin_account_users')->pluck('user_id'))->value('id');
if ($nobody) {
    ok('a person tagged on no account sees an empty pill', $watch->unreadCount((int) $nobody), 0);
}

// ─────────────────────────────────────────────────────────────────────────────
section('8. WATCH now includes transaction_date — and must not cry wolf');

// ⚠ `transaction_date` is cast 'date'. If Eloquent's dirty check disagreed with the cast, every
// save() of a ledger row would write a spurious "date changed" audit entry. Prove it does not.
$auditBefore = DB::table('t_sys_audit_log')->where('entity_type', 'ledger')->where('entity_id', $mv->id)->count();
$mv->description = 'PROOF description-only save';
$mv->save();
$mv->refresh();
$mv->amount = (float) $mv->amount;          // same value, re-assigned
$mv->save();
$spurious = DB::table('t_sys_audit_log')->where('entity_type', 'ledger')->where('entity_id', $mv->id)
    ->where('changes', 'like', '%transaction_date%')->count();
ok('saving a row without touching its date logs NO date change', $spurious, 0);

$mv->transaction_date = now()->subDays(5)->toDateString();
$mv->save();
$logged = DB::table('t_sys_audit_log')->where('entity_type', 'ledger')->where('entity_id', $mv->id)
    ->where('action', 'updated')->where('changes', 'like', '%transaction_date%')->count();
ok('actually re-dating a row now writes an audit entry (it used to write nothing)', $logged, 1);

// ─────────────────────────────────────────────────────────────────────────────
section('9. The reconciliation identity still holds after all of this');

$acctNow = AccountModel::find($acct->id);
$rebuilt = (float) $acctNow->opening_balance;
LedgerModel::where(fn ($q) => $q->where('from_account_id', $acct->id)->orWhere('to_account_id', $acct->id))
    ->where('balance_updated', 1)
    ->chunkById(2000, function ($chunk) use (&$rebuilt, $acct) {
        foreach ($chunk as $r) { $rebuilt += $r->effectOnAccount((int) $acct->id); }
    });
ok('stored balance = opening + every applied row (counts changed nothing)',
    round((float) $acctNow->current_balance, 2), round($rebuilt, 2));

} catch (\Throwable $e) {
    echo "\n!! EXCEPTION: " . $e->getMessage() . "\n   " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    DB::rollBack();
    echo "\n(transaction rolled back — the replica is untouched)\n";
}

echo "\n" . str_repeat('─', 52) . "\n";
echo ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
