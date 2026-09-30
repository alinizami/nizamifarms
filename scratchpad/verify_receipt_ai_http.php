<?php
// Sep-27 🤖 receipt suggestions: render the vendor page (the web scan card lives there) through
// the REAL HTTP kernel as Qasim, check the new pieces are on it and every inline script parses,
// then hand the card's hint/quantity code to the mobile parity test.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
use Illuminate\Http\Request; use Illuminate\Support\Str; use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Crypt; use Illuminate\Cookie\CookieValuePrefix;
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
$pass = 0; $fail = 0;
function ok($w, $c, $x = '') { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $w\n"; } else { $fail++; echo "  ✗ $w" . ($x ? " ($x)" : '') . "\n"; } }
$sid = Str::random(40);
file_put_contents(storage_path('framework/sessions/' . $sid), serialize(['login_web_' . sha1(SessionGuard::class) => 91, '_token' => Str::random(40)]));
$cookie = config('session.cookie');
$enc = Crypt::encrypt(CookieValuePrefix::create($cookie, Crypt::getKey()) . $sid, false);
$r = Request::create('/finance/vendors/50', 'GET'); $r->cookies->set($cookie, $enc);
$res = $kernel->handle($r); $b = $res->getContent();
ok('vendor page 200 as Qasim', $res->getStatusCode() === 200, (string) $res->getStatusCode());
ok('the card has the 🤖 hints and the ⚖ quantity check', str_contains($b, 'function rcHints') && str_contains($b, 'function rcPackFit') && str_contains($b, 'rc-ai-yes') && str_contains($b, 'rc-fit-use'));
ok('switching product goes through rcChoose (printed numbers restored)', substr_count($b, 'rcChoose(') >= 3);
ok('the new-product form takes the reader\'s ingredient first', str_contains($b, "ai = card.lines[i].ai_ingredient"));
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $b, $m);
$bad = 0;
foreach ($m[1] as $i => $js) {
    if (trim($js) === '') { continue; }
    $tmp = sys_get_temp_dir() . "/vrai_{$i}.js"; file_put_contents($tmp, $js);
    exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($out, 0, 3)) . "\n"; }
    $out = [];
}
ok('every inline script parses (' . count($m[1]) . ')', $bad === 0);
foreach ($m[1] as $js) {
    $a = strpos($js, 'var RC_UNITS =');
    $z = strpos($js, 'function render() {');
    if ($a !== false && $z !== false && $z > $a) {
        file_put_contents(__DIR__ . '/../../NizamiFarmsMobile/__tests__/fixtures/webReceiptHints.js.txt', substr($js, $a, $z - $a));
        ok('card code handed to the mobile parity test', true);
    }
}
@unlink(storage_path('framework/sessions/' . $sid));
echo "\n" . ($fail === 0 ? '✅' : '❌') . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
