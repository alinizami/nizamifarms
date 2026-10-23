<?php
/**
 * PROOF — 🔴⏳ Tag alerts + tagger reminders, round 1 (Oct-19-2026).
 * Plan: TAG-ALERTS-PLAN-OCT19-2026.md. Runs on the local replica inside ONE transaction
 * that is ROLLED BACK at the end: nothing persists.
 *
 * ⚠ SAFETY: no push and no WhatsApp call can leave this script —
 *   • every outgoing HTTP request is faked (Http::fake + preventStrayRequests);
 *   • FirebaseService is replaced by a recorder (no FCM at all);
 *   • WhatsAppService's blue-tick receipt (markRead calls it) is replaced by a no-op.
 *
 *   php test_mention_followups_oct19.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\FirebaseService;
use App\Services\WhatsApp\MentionFollowupService as MFS;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; echo "  ✔ $label\n"; } else { $fail++; echo "  ✘ $label\n"; } }

// ── fakes ────────────────────────────────────────────────────────────────
Http::preventStrayRequests();
Http::fake(['*' => Http::response(['name' => 'fake'], 200)]);

class RecFirebase extends FirebaseService {
    public array $calls = [];
    public function __construct() {}
    public function notifyTeamAlert(int $u, string $t, string $b, array $d): void { $this->calls[] = ['team', $u, $t, $b, $d]; }
    public function notifyFollowupToTagger(int $u, string $t, string $b, array $d): void { $this->calls[] = ['tagger', $u, $t, $b, $d]; }
    public function clearTeamAlert(int $u, int $c): void { $this->calls[] = ['clear', $u, $c]; }
    public function notifyWhatsAppMention(int $u, string $by, string $conv, int $cid): void { $this->calls[] = ['legacy', $u, $by, $conv, $cid]; }
    public function notifyUser(int $userId, array $n, array $d = [], string $ch = 'whatsapp_messages', ?string $p = null): void { $this->calls[] = ['notifyUser', $userId]; }
    public function reset(): void { $this->calls = []; }
    public function of(string $kind, ?int $user = null): array { return array_values(array_filter($this->calls, fn ($c) => $c[0] === $kind && ($user === null || $c[1] === $user))); }
}
class QuietWhatsApp extends WhatsAppService {
    public int $receipts = 0;
    public function markInboundAsReadOnWhatsApp(int $conversationId): int { $this->receipts++; return 0; }
    public function isSuperReader($user): bool { return false; }
}
$fb = new RecFirebase();
app()->instance(FirebaseService::class, $fb);
$wa = new QuietWhatsApp();
app()->instance(WhatsAppService::class, $wa);

$TAIMUR = 68; $FAROOQ = 74; $SHABIB = 79; $NIZAMI = 67;
$L_TAIMUR = 6; $L_QURBANI = 10; $L_SHABIB = 7;
$taimur = User::find($TAIMUR); $farooq = User::find($FAROOQ); $shabib = User::find($SHABIB);
$CONVS = DB::table('t_wa_conversations')->whereNotIn('id', DB::table('t_wa_conversation_labels')->pluck('conversation_id'))
    ->orderByDesc('id')->limit(12)->pluck('id')->map(fn ($x) => (int) $x)->all();

function svc(): MFS { $s = app(MFS::class); return $s; }
function actAs(User $u): void { Auth::shouldUse('web'); Auth::guard('web')->setUser($u); }
function webCtl() { return app(\App\Http\Controllers\Web\WhatsAppWebController::class); }
function apiCtl() { return app(\App\Http\Controllers\API\WhatsAppController::class); }
function tagWeb(User $by, int $conv, int $label) { actAs($by); $r = Request::create("/messages/conversations/$conv/labels", 'POST', ['label_id' => $label]); app()->instance('request', $r); return webCtl()->applyLabel($r, $conv); }
function tagApi(User $by, int $conv, int $label) { actAs($by); $r = Request::create("/api/whatsapp/conversations/$conv/labels", 'POST', ['label_id' => $label]); app()->instance('request', $r); return apiCtl()->applyLabel($r, $conv); }
function openWeb(User $u, int $conv) { actAs($u); return webCtl()->markRead(Request::create('/x', 'POST'), $conv); }
function openApi(User $u, int $conv) { actAs($u); return apiCtl()->markRead(Request::create('/x', 'POST'), $conv); }
function untagWeb(User $by, int $conv, int $label) { actAs($by); return webCtl()->removeLabel(Request::create('/x', 'DELETE'), $conv, $label); }
function cyc(int $conv) { return DB::table('t_wa_mention_followup')->where('conversation_id', $conv)->orderByDesc('id')->first(); }
function cycles(int $conv): int { return DB::table('t_wa_mention_followup')->where('conversation_id', $conv)->count(); }
function at(Carbon $base, int $min, int $sec = 0): Carbon { $t = $base->copy()->addMinutes($min)->addSeconds($sec); Carbon::setTestNow($t); return $t; }

DB::beginTransaction();
try {
    Cache::forget('mention_followups_tick');
    $T0 = Carbon::parse(date('Y-m-d') . ' 10:00:00');
    Carbon::setTestNow($T0);

    echo "\n[0] Switch + safety\n";
    ok(svc()->ready(), 'table installed');
    ok(svc()->enabledUserIds() === [$TAIMUR], 'enabled list = [68] (Taimur only)');
    ok(count($CONVS) >= 10, 'found ' . count($CONVS) . ' untagged chats to test on');

    echo "\n[1] Not on the list → today's push, unchanged\n";
    $c = $CONVS[0];
    $fb->reset();
    tagWeb($farooq, $c, $L_SHABIB);       // @Shabib is not on the list
    ok(cycles($c) === 0, 'tagging Shabib opens NO cycle');
    ok(count($fb->of('legacy', $SHABIB)) === 1 && !$fb->of('team'), "today's mention push to Shabib still fires (once)");
    $fb->reset();
    tagWeb($farooq, $c, $L_SHABIB);       // re-apply
    ok(!$fb->of('legacy'), 're-applying a non-list tag still pushes nothing (unchanged behaviour)');
    $fb->reset();
    tagWeb($taimur, $CONVS[1], $L_TAIMUR); // self-tag
    ok(cycles($CONVS[1]) === 0 && !$fb->calls, 'Taimur tagging himself → no cycle, no push');
    DB::table('t_wa_conversation_labels')->where('conversation_id', $CONVS[1])->delete();

    echo "\n[2] Shabib tags Taimur (web) → one cycle, loud alert, no legacy push\n";
    $c = $CONVS[2];
    $fb->reset();
    tagWeb($shabib, $c, $L_TAIMUR);
    $r = cyc($c);
    ok($r && $r->state === 'watching' && (int) $r->on_user_id === $TAIMUR && (int) $r->waiting_user_id === $SHABIB, 'cycle watching, on=Taimur, waiting=Shabib');
    ok(Carbon::parse($r->next_at)->equalTo($T0->copy()->addMinutes(10)), 'first reminder due at +10 min');
    ok(count($fb->of('team', $TAIMUR)) === 1 && $fb->of('team')[0][4]['kind'] === 'first', 'Taimur gets ONE first alert');
    ok(str_contains($fb->of('team')[0][2], 'Shabib'), 'alert names who tagged: "' . $fb->of('team')[0][2] . '"');
    ok(!$fb->of('legacy'), 'old mention push NOT also sent (no double buzz)');
    ok((int) $r->conversation_label_id > 0, 'cycle points at the tag row');

    echo "\n[3] Double tap / re-tag while unopened / second Taimur label → nothing new\n";
    $fb->reset();
    tagWeb($shabib, $c, $L_TAIMUR);
    tagApi($farooq, $c, $L_TAIMUR);
    tagWeb($shabib, $c, $L_QURBANI);   // "Qurbani Complaint" is ALSO owned by Taimur
    ok(cycles($c) === 1, 'still exactly one cycle');
    ok(!$fb->calls, 'no push of any kind');

    echo "\n[4] The clock: nothing before +10, one reminder at +10, no repeat in the same window\n";
    $fb->reset();
    at($T0, 9, 59); svc()->processDue();
    ok(!$fb->calls, '+9:59 → nothing');
    at($T0, 10); $o = svc()->processDue();
    ok($o['reminders'] === 1, '+10 → one reminder');
    $tg = $fb->of('tagger', $SHABIB);
    ok(count($tg) === 1 && str_contains($tg[0][2], "hasn't opened your tag · 10 min"), 'Shabib: "' . ($tg[0][2] ?? '') . '"');
    ok(count($fb->of('team', $TAIMUR)) === 1 && $fb->of('team')[0][4]['kind'] === 'rebuzz', 'Taimur re-buzzed once');
    ok(!$fb->of('tagger', $FAROOQ), 'Farooq (who did not tag) hears nothing');
    $fb->reset();
    svc()->processDue(); svc()->processDue();
    ok(!$fb->calls, 'running the tick twice more at +10 → nothing (claimed)');
    ok(Carbon::parse(cyc($c)->next_at)->equalTo($T0->copy()->addMinutes(25)), 'next due at +25');

    echo "\n[5] Catch-up: tick arrives at +60 (missed 25/40/55) → ONE reminder, not three\n";
    $fb->reset();
    at($T0, 60); svc()->processDue();
    ok(count($fb->of('tagger')) === 1 && count($fb->of('team')) === 1, 'one reminder + one re-buzz');
    $r = cyc($c);
    ok((int) $r->remind_count === 2, 'counted as reminder 2');
    ok(Carbon::parse($r->next_at)->equalTo($T0->copy()->addMinutes(75)), 'next = now + 15 (+75)');

    echo "\n[6] Buttons: who may press, and what they do\n";
    $id = (int) $r->id;
    ok(svc()->act($id, $TAIMUR, 'wait10')['code'] === 'forbidden', 'Taimur cannot press Wait (tagger only)');
    ok(svc()->act($id, $FAROOQ, 'stop')['code'] === 'forbidden', 'Farooq cannot press Stop on Shabib\'s tag');
    ok(svc()->act($id, $SHABIB, 'on_it')['code'] === 'forbidden', 'Shabib cannot press On it (Taimur only)');
    ok(svc()->act($id, $SHABIB, 'dance')['code'] === 'bad_action', 'unknown action refused');
    at($T0, 61);
    $a = svc()->act($id, $SHABIB, 'wait10');
    ok($a['ok'] && Carbon::parse(cyc($c)->next_at)->equalTo($T0->copy()->addMinutes(71)), 'Wait 10 → next at +71');
    at($T0, 62);
    $a = svc()->act($id, $SHABIB, 'called');
    ok($a['ok'] && Carbon::parse(cyc($c)->next_at)->equalTo($T0->copy()->addMinutes(77)) && cyc($c)->last_action === 'called', "I'll call him → next at +77, recorded");
    $fb->reset();
    at($T0, 70); svc()->processDue();
    ok(!$fb->calls, 'nothing fires at +70 (moved to +77)');
    at($T0, 77); svc()->processDue();
    ok(count($fb->of('tagger')) === 1, 'reminder fires at +77');
    // the two HTTP endpoints (web strip + phone) — status codes and payload
    actAs($taimur);
    $resp = apiCtl()->mentionFollowupAction(Request::create('/x', 'POST', ['action' => 'stop']), $id);
    ok($resp->getStatusCode() === 403, 'phone endpoint: Taimur pressing Stop → 403');
    actAs($shabib);
    $resp = webCtl()->mentionFollowupAction(Request::create('/x', 'POST', ['action' => 'called']), $id);
    $j = json_decode($resp->getContent(), true);
    ok($resp->getStatusCode() === 200 && $j['success'] && ($j['followup']['state'] ?? null) === 'watching', 'web endpoint: Shabib "called" → 200 + fresh strip');
    $resp = apiCtl()->mentionFollowupAction(Request::create('/x', 'POST', ['action' => 'called']), 999999999);
    ok($resp->getStatusCode() === 404, 'phone endpoint: unknown cycle → 404');
    DB::table('t_wa_mention_followup')->where('id', $id)->update(['next_at' => $T0->copy()->addMinutes(77)]); // back to the schedule under test
    $s = svc()->stripForViewer($c, $SHABIB);
    ok($s && $s['state'] === 'watching' && $s['can_act'] && $s['minutes'] === 77, 'Shabib\'s chat strip: watching, 77 min, buttons on');
    ok(svc()->stripForViewer($c, $TAIMUR) === null && svc()->stripForViewer($c, $FAROOQ) === null, 'nobody else gets the strip');

    echo "\n[7] Taimur opens the chat on the WEB → closes at once, tagger told, sticky cleared\n";
    $fb->reset(); $wa->receipts = 0;
    at($T0, 80); openWeb($taimur, $c);
    $r = cyc($c);
    ok($r->state === 'opened' && Carbon::parse($r->opened_at)->equalTo($T0->copy()->addMinutes(80)) && $r->next_at === null, 'state opened at +80, nothing scheduled');
    ok(DB::table('t_wa_conversation_labels')->where('conversation_id', $c)->where('label_id', $L_TAIMUR)->value('mention_seen_at') !== null, 'tag still stamped seen (existing pin behaviour intact)');
    $op = $fb->of('tagger', $SHABIB);
    ok(count($op) === 1 && str_contains($op[0][2], 'opened it') && str_contains($op[0][3], 'after 80 min'), 'Shabib: "' . ($op[0][2] ?? '') . ' / ' . ($op[0][3] ?? '') . '"');
    ok(count($fb->of('clear', $TAIMUR)) === 1, 'sticky alert cleared on Taimur\'s phones');
    ok($wa->receipts === 1, '(blue-tick receipt path ran against the no-op fake)');
    $fb->reset();
    openWeb($taimur, $c); openApi($taimur, $c);
    at($T0, 95); svc()->processDue();
    ok(!$fb->calls, 'opening again / later ticks → nothing at all');
    $s = svc()->stripForViewer($c, $SHABIB);
    ok($s && $s['state'] === 'opened' && $s['opened_at'] === '11:20', 'strip shows "opened 11:20" for a while');
    at($T0, 111);
    ok(svc()->stripForViewer($c, $SHABIB) === null, '…and disappears 30 min after');
    ok(svc()->act((int) $r->id, $SHABIB, 'wait10')['code'] === 'already_closed', 'stale Wait tap → "already closed", 200, nothing happens');

    echo "\n[8] Re-tag AFTER he opened → fresh cycle + fresh alert (the old silent re-tag gap)\n";
    $fb->reset();
    tagApi($farooq, $c, $L_TAIMUR);
    ok(cycles($c) === 2 && cyc($c)->state === 'watching' && (int) cyc($c)->waiting_user_id === $FAROOQ, 'new cycle, waiting = Farooq');
    ok(count($fb->of('team', $TAIMUR)) === 1, 'Taimur alerted again');

    echo "\n[9] Opened within 10 min → the tagger hears NOTHING\n";
    $fb->reset();
    at($T0, 120); openApi($taimur, $c);   // 9 min after Farooq's re-tag at +111
    ok(cyc($c)->state === 'opened', 'closed as opened (phone API)');
    ok(!$fb->of('tagger'), 'no reminder, no "opened" push to Farooq');

    echo "\n[10] Stop → ALL buzzing ends (tagger AND Taimur); his late open is silent\n";
    $c = $CONVS[3]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    at($T0, 10); svc()->processDue();
    $id = (int) cyc($c)->id;
    at($T0, 12); svc()->act($id, $SHABIB, 'stop');
    $fb->reset();
    foreach ([25, 40, 70, 100, 125, 200] as $m) { at($T0, $m); svc()->processDue(); }
    ok(!$fb->calls, 'no push to anyone after Stop (checked to +200)');
    ok(cyc($c)->state === 'muted', 'state muted');
    at($T0, 90); openWeb($taimur, $c);
    ok(cyc($c)->state === 'opened' && !$fb->of('tagger'), 'he opens at +90 → closed, Shabib not pushed (he asked for quiet)');
    $s = svc()->stripForViewer($c, $SHABIB);
    ok($s && $s['state'] === 'opened', 'strip still tells Shabib it was opened');

    echo "\n[11] On it → tagger told once, reminders stop; a later open is recorded\n";
    $c = $CONVS[4]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    at($T0, 10); svc()->processDue();
    $id = (int) cyc($c)->id;
    $fb->reset();
    at($T0, 14); $a = svc()->act($id, $TAIMUR, 'on_it');
    ok($a['ok'] && cyc($c)->state === 'acknowledged', 'On it → acknowledged');
    ok(count($fb->of('tagger', $SHABIB)) === 1 && str_contains($fb->of('tagger')[0][2], 'is on it'), 'Shabib: "' . ($fb->of('tagger')[0][2] ?? '') . '"');
    ok(count($fb->of('clear', $TAIMUR)) === 1, 'his alert cleared');
    $fb->reset();
    foreach ([25, 40, 130] as $m) { at($T0, $m); svc()->processDue(); }
    ok(!$fb->calls, 'no more reminders after On it');
    ok(svc()->act($id, $TAIMUR, 'on_it')['code'] === 'already_closed', 'second On it → already closed');
    at($T0, 50); openWeb($taimur, $c);
    $r = cyc($c);
    ok($r->state === 'acknowledged' && $r->opened_at !== null && !$fb->of('tagger'), 'later open: opened_at stamped, state kept, no push');

    echo "\n[12] Full 2-hour run, ticking EVERY minute → exactly 8 reminders + 1 give-up\n";
    $c = $CONVS[5]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    $fb->reset();
    $remAt = [];
    for ($m = 1; $m <= 180; $m++) {
        at($T0, $m); $before = count($fb->of('tagger'));
        svc()->processDue();
        if (count($fb->of('tagger')) > $before) $remAt[] = $m;
    }
    ok($remAt === [10, 25, 40, 55, 70, 85, 100, 115, 120], 'tagger pushed at ' . implode(',', $remAt));
    $tg = $fb->of('tagger');
    ok(str_contains(end($tg)[2], "hasn't opened it in 2 hours") && str_contains(end($tg)[3], 'Daily Issues'), 'last push = give-up, mentions Daily Issues');
    ok(count($fb->of('team', $TAIMUR)) === 8, 'Taimur re-buzzed exactly 8 times (none after give-up)');
    ok(cyc($c)->state === 'gave_up' && cyc($c)->next_at === null, 'state gave_up');
    $s = svc()->stripForViewer($c, $SHABIB);
    ok($s && $s['state'] === 'gave_up' && !$s['can_act'], 'strip (at +180, still unopened): gave up, no buttons');
    $fb->reset();
    at($T0, 190); openWeb($taimur, $c);
    $r = cyc($c);
    ok($r->state === 'opened' && Carbon::parse($r->opened_at)->equalTo($T0->copy()->addMinutes(190)), 'opened AFTER give-up → open time recorded (+190)');
    ok(!$fb->of('tagger'), '…with no push to the tagger (he was already told)');
    $flag = collect(svc()->dailyIssues($T0->format('Y-m-d'))[0]['flagged'] ?? [])->firstWhere('cycle_id', (int) $r->id);
    ok($flag && $flag['answered'] && $flag['waited_min'] === 190, 'Daily Issues: "' . ($flag['response'] ?? '?') . '", waited 190 min');
    // removing a tag after it already ran out must not erase the delay
    $c2 = $CONVS[11]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c2, $L_TAIMUR);
    at($T0, 125); svc()->processDue();
    untagWeb($shabib, $c2, $L_TAIMUR);
    ok(cyc($c2)->state === 'gave_up', 'untag after give-up keeps gave_up (still in Daily Issues)');

    echo "\n[13] Server down for 2 h → first tick at +130 gives up straight away (no backlog)\n";
    $c = $CONVS[6]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    $fb->reset();
    at($T0, 130); svc()->processDue();
    ok(count($fb->of('tagger')) === 1 && str_contains($fb->of('tagger')[0][2], '2 hours') && !$fb->of('team'), 'one give-up push, zero reminders, zero re-buzz');

    echo "\n[14] Tag removed → closed silently\n";
    $c = $CONVS[7]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    $fb->reset();
    at($T0, 5); untagWeb($shabib, $c, $L_TAIMUR);
    ok(cyc($c)->state === 'untagged', 'state untagged');
    at($T0, 10); svc()->processDue();
    ok(!$fb->of('tagger') && !$fb->of('team'), 'no reminder after untag');

    echo "\n[15] Tag row vanished without the hook / stamped seen by another path → tick self-heals\n";
    $c = $CONVS[8]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    DB::table('t_wa_conversation_labels')->where('conversation_id', $c)->delete();
    $fb->reset(); at($T0, 10); svc()->processDue();
    ok(cyc($c)->state === 'untagged' && !$fb->of('tagger'), 'missing tag row → untagged, nothing sent');
    $c = $CONVS[9]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    DB::table('t_wa_conversation_labels')->where('conversation_id', $c)->update(['mention_seen_at' => $T0->copy()->addMinutes(4)]);
    $fb->reset(); at($T0, 10); svc()->processDue();
    ok(cyc($c)->state === 'opened' && Carbon::parse(cyc($c)->opened_at)->equalTo($T0->copy()->addMinutes(4)) && !$fb->of('tagger'), 'seen at +4 by another path → opened at +4, no reminder');

    echo "\n[16] Throttle: the cron and dozens of polls share one tick per 50 s\n";
    Cache::forget('mention_followups_tick');
    ok(is_array(svc()->tickLocked()), 'first tick runs');
    ok(svc()->tickLocked() === null, 'second tick inside 50 s is skipped');
    Cache::forget('mention_followups_tick');
    ok(\Illuminate\Support\Facades\Artisan::call('whatsapp:mention-followups') === 0, 'artisan whatsapp:mention-followups exits 0');

    echo "\n[17] Daily Issues — the 1-hour rule\n";
    $day = '2026-10-10';
    $mk = function (int $conv, string $tagged, ?string $opened, ?string $ack, string $state = 'opened') use ($TAIMUR, $SHABIB) {
        return DB::table('t_wa_mention_followup')->insertGetId([
            'conversation_id' => $conv, 'label_id' => 6, 'on_user_id' => $TAIMUR, 'waiting_user_id' => $SHABIB,
            'tagged_at' => $tagged, 'opened_at' => $opened, 'acknowledged_at' => $ack, 'state' => $state,
            'remind_count' => 3, 'created_at' => $tagged, 'updated_at' => $tagged,
        ]);
    };
    Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00'));
    $mk($CONVS[0], "$day 09:00:00", "$day 09:59:00", null);                 // 59 min → fine
    $mk($CONVS[1], "$day 10:00:00", "$day 11:01:00", null);                 // 61 min → FLAGGED (opened late still counts)
    $mk($CONVS[2], "$day 12:00:00", null, null, 'gave_up');                  // never → FLAGGED
    $mk($CONVS[3], "$day 13:00:00", "$day 14:30:00", "$day 13:30:00", 'acknowledged'); // On it at 30 → fine
    $mk($CONVS[4], "$day 14:00:00", null, null, 'untagged');                 // removed → not counted
    $mk($CONVS[5], "$day 23:30:00", '2026-10-11 00:45:00', null);           // across midnight, 75 min → FLAGGED on the 10th
    $mk($CONVS[6], "$day 15:00:00", "$day 16:30:00", null, 'opened');       // muted earlier but opened at 90 → FLAGGED
    $di = svc()->dailyIssues($day);
    ok(count($di) === 1 && $di[0]['on_user'] === 'Taimur', 'one person listed: Taimur');
    ok($di[0]['tagged_count'] === 6, 'tagged 6 times (untagged one not counted)');
    ok($di[0]['flagged_count'] === 4, '4 took over 1 h (61 min, never, across midnight, 90 min)');
    $byTime = collect($di[0]['flagged'])->keyBy('tagged_at');
    ok(isset($byTime['10:00']) && $byTime['10:00']['response'] === 'opened 11:01' && $byTime['10:00']['waited_min'] === 61, '10:00 → "opened 11:01", 61 min');
    ok(isset($byTime['12:00']) && $byTime['12:00']['response'] === 'not opened' && !$byTime['12:00']['answered'], '12:00 → "not opened"');
    ok(isset($byTime['23:30']) && str_contains($byTime['23:30']['response'], '00:45, 11 Oct'), '23:30 → counted on the 10th, "' . ($byTime['23:30']['response'] ?? '') . '"');
    ok(!isset($byTime['09:00']) && !isset($byTime['13:00']), '59-min open and On-it-in-30 are NOT flagged');
    ok(svc()->dailyIssues('2026-10-11') === [], 'the next day has nothing (the 23:30 tag belongs to the 10th)');
    // a fresh tag that is not yet an hour old is not shown
    Carbon::setTestNow(Carbon::parse("$day 18:30:00"));
    $mk($CONVS[7], "$day 18:00:00", null, null, 'watching');
    $di = svc()->dailyIssues($day);
    ok(!collect($di[0]['flagged'])->contains('tagged_at', '18:00'), 'a tag 30 min old and unanswered is not flagged yet');
    Carbon::setTestNow(Carbon::parse("$day 19:05:00"));
    $di = svc()->dailyIssues($day);
    ok(collect($di[0]['flagged'])->contains('tagged_at', '18:00'), '…and is flagged once 65 min pass');
    // the Daily Issues endpoint carries it
    actAs($shabib);
    $resp = app(\App\Http\Controllers\CRM\RiderReportsController::class)->index(Request::create('/x', 'GET', ['date' => date('Y-m-d')]), app(\App\Services\Riders\RiderDayReportService::class));
    $j = json_decode($resp->getContent(), true);
    ok(array_key_exists('team_issues', $j) && is_array($j['team_issues']), 'Daily Issues JSON carries team_issues (' . count($j['team_issues']) . ' person(s) today)');

    echo "\n[18] register-device: capabilities stored, and RESET to NULL by an old app\n";
    Carbon::setTestNow($T0);
    actAs($taimur);
    $tok = 'TEST-TOKEN-' . uniqid();
    apiCtl()->registerDevice(Request::create('/x', 'POST', ['fcm_token' => $tok, 'app_flavor' => 'messages', 'capabilities' => 'team_alerts']));
    ok(DB::table('t_wa_device_tokens')->where('fcm_token', $tok)->value('capabilities') === 'team_alerts', 'new APK → "team_alerts" stored');
    apiCtl()->registerDevice(Request::create('/x', 'POST', ['fcm_token' => $tok, 'app_flavor' => 'messages']));
    ok(DB::table('t_wa_device_tokens')->where('fcm_token', $tok)->value('capabilities') === null, 'same token from an OLD APK → NULL (never silent pushes)');
    apiCtl()->registerDevice(Request::create('/x', 'POST', ['fcm_token' => $tok, 'capabilities' => 'Team_Alerts;DROP<x>']));
    ok(DB::table('t_wa_device_tokens')->where('fcm_token', $tok)->value('capabilities') === 'team_alertsdropx', 'junk is sanitised to [a-z0-9_,]');

    echo "\n[19] FirebaseService delivery shape per token (sends recorded, never made)\n";
    $real = new class extends FirebaseService {
        public array $sent = [];
        public function __construct() { $this->projectId = 'test-project'; $this->credentialsPath = __FILE__; }
        protected function getAccessToken(): ?string { return 'x'; }
        protected function sendToDevice(string $a, string $t, array $n, array $d = [], string $c = 'whatsapp_messages'): bool { $this->sent[] = ['plain', $t, $d['type'] ?? null, $c, $n['title'] ?? null]; return true; }
        protected function sendDataOnly(string $a, string $t, array $d): bool { $this->sent[] = ['data', $t, $d['type'] ?? null, $d]; return true; }
    };
    DB::table('t_wa_device_tokens')->where('user_id', $TAIMUR)->update(['is_active' => 0]);
    DB::table('t_wa_device_tokens')->where('fcm_token', $tok)->delete();
    $ins = fn ($t, $fl, $cap) => DB::table('t_wa_device_tokens')->insert(['user_id' => $TAIMUR, 'fcm_token' => $t, 'device_type' => 'android', 'app_flavor' => $fl, 'capabilities' => $cap, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $ins('T-MSG-NEW', 'messages', 'team_alerts'); $ins('T-MSG-OLD', 'messages', null); $ins('T-PRIMARY', 'primary', null);
    $real->notifyTeamAlert($TAIMUR, 'Hi', 'Body', ['cycle_id' => 5, 'conversation_id' => 9]);
    $byTok = collect($real->sent)->keyBy(1);
    ok(isset($byTok['T-MSG-NEW']) && $byTok['T-MSG-NEW'][0] === 'data' && $byTok['T-MSG-NEW'][2] === 'team_alert', 'new-APK token → data-only "team_alert"');
    ok(isset($byTok['T-MSG-NEW']) && $byTok['T-MSG-NEW'][3]['cycle_id'] === '5' && $byTok['T-MSG-NEW'][3]['title'] === 'Hi', '…values are strings, title/body inside');
    ok(isset($byTok['T-MSG-OLD']) && $byTok['T-MSG-OLD'][0] === 'plain' && $byTok['T-MSG-OLD'][2] === 'whatsapp_mention' && $byTok['T-MSG-OLD'][3] === 'whatsapp_messages', 'old-APK token → today\'s plain whatsapp_mention push');
    ok(!isset($byTok['T-PRIMARY']), 'Rider-app token skipped (he has NF Messages) → no double buzz');
    $real->sent = [];
    $real->clearTeamAlert($TAIMUR, 5);
    ok(count($real->sent) === 1 && $real->sent[0][1] === 'T-MSG-NEW' && $real->sent[0][2] === 'team_alert_clear', 'clear goes ONLY to the phone that draws a sticky alert');
    $real->sent = [];
    $real->notifyFollowupToTagger($TAIMUR, 'R', 'B', ['cycle_id' => 5]);
    ok(collect($real->sent)->firstWhere(1, 'T-MSG-NEW')[2] === 'team_followup', 'tagger reminder to a new-APK token → "team_followup"');

    echo "\n[20] Off switch: empty the config → everything back to today's behaviour\n";
    DB::table('t_fin_config')->where('config_key', 'MENTION_FOLLOWUP_USER_IDS')->update(['config_value' => '']);
    $fb->reset();
    $c = $CONVS[10]; Carbon::setTestNow($T0);
    tagWeb($shabib, $c, $L_TAIMUR);
    ok(cycles($c) === 0 && count($fb->of('legacy', $TAIMUR)) === 1 && !$fb->of('team'), 'no cycle; Taimur gets today\'s mention push');
} catch (\Throwable $e) {
    $fail++;
    echo "  ✘ EXCEPTION: " . $e->getMessage() . "\n    at " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    Carbon::setTestNow();
    DB::rollBack();
}

echo "\n" . ($fail ? "❌" : "✅") . " $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
