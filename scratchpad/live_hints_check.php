<?php
// ONE capped live call (MAX_OUTPUT_TOKENS 6144, 25 s per try) — the Mega slip with Imtiaz frozen's list.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Storage;
$img = getenv('IMG');
$disk = Storage::disk(config('whatsapp.media_disk', 'public'));
$disk->put('proof-hints/mega.jpg', file_get_contents($img));
$vendor = App\Models\FIN\VendorModel::find(50);
$ctl = app(App\Http\Controllers\Khaas\ReceiptController::class);
$m = new ReflectionMethod($ctl, 'readerHints'); $m->setAccessible(true);
$hints = $m->invoke($ctl, $vendor);
echo "sending ", count($hints['products']), " products, ", count($hints['ingredients']), " ingredients\n";
$names = []; foreach ($hints['products'] as $p) $names['P'.$p['id']] = $p['name']; foreach ($hints['ingredients'] as $i) $names['I'.$i['id']] = $i['name'];
$t = microtime(true);
$svc = (new App\Services\Khaas\ReceiptExtractionService())->withHints($hints);
$r = $svc->extract('proof-hints/mega.jpg');
printf("%.1fs\n", microtime(true) - $t);
if (!$r) { echo "FAILED: ", json_encode($svc->lastFailure()), "\n"; $disk->deleteDirectory('proof-hints'); exit(1); }
$sum = 0;
foreach ($r['lines'] as $l) {
    $sum += (float) $l['line_total'];
    printf("%-38s %6s x %8s = %9s | match: %-18s | ingredient: %s\n", mb_substr($l['raw_name'], 0, 38), $l['qty'], $l['unit_price'], $l['line_total'],
        $l['ai_match'] ? $names['P'.$l['ai_match']] : '—', $l['ai_ingredient'] ? $names['I'.$l['ai_ingredient']] : '—');
}
printf("lines=%d  sum=%.2f  printed=%s  tokens in=%d out=%d\n", count($r['lines']), $sum, $r['grand_total'], $r['tokens_in'], $r['tokens_out']);
file_put_contents(__DIR__ . '/live_hints_result.json', json_encode($r));
$disk->deleteDirectory('proof-hints');
