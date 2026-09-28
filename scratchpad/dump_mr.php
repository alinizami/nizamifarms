<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out = __DIR__ . '/../../NizamiFarmsMobile/__tests__/fixtures';
@mkdir($out, 0777, true);
$m = new ReflectionMethod(App\Http\Controllers\KhaasController::class, 'buildMonthReview');
$m->setAccessible(true);
foreach (['taimur' => 68, 'qasim' => 91] as $who => $uid) {
    app('auth')->forgetGuards();
    app('auth')->guard('web')->setUser(App\Models\User::find($uid));
    app('auth')->shouldUse('web');
    $d = ['success' => true] + $m->invoke(app(App\Http\Controllers\KhaasController::class), Illuminate\Http\Request::create('/x', 'GET', ['month' => '2026-09']), 2);
    file_put_contents("$out/monthReviewSep2026.$who.json", json_encode($d));
    echo "$who ok ", strlen(json_encode($d)), "\n";
}
copy(__DIR__ . '/recipe_1313_taimur.json', "$out/recipe1313.taimur.json");
copy(__DIR__ . '/recipe_1313_qasim.json', "$out/recipe1313.qasim.json");
