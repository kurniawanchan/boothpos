<?php

/**
 * 093 T038 — bukti keserentakan NYATA (proses terpisah, koneksi MySQL terpisah). Bukan bagian suite PHPUnit: PHPUnit satu
 * proses dengan data dalam transaksi tidak bisa membuktikan balapan.
 *
 * Pakai (dari host):
 *   docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array \
 *     app php tests/Concurrency/ingredient_race.php
 *
 * PERINGATAN: menjalankan `migrate:fresh` pada database uji. Menolak berjalan bila nama database tidak mengandung "test".
 * Data hasilnya tertinggal di database uji sampai `php artisan test` berikutnya me-reset-nya (RefreshDatabase).
 */

use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
use App\Models\VariantOutletStock;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.mysql.database');
if (! str_contains($database, 'test')) {
    fwrite(STDERR, "MENOLAK: database '{$database}' bukan database uji.\n");
    exit(2);
}

// ---------------------------------------------------------------------------------------------------------------------
// Mode pekerja: satu pesanan, menunggu penghalang bersama supaya semua mulai berbarengan.
// ---------------------------------------------------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    [, , $ctxFile, $i, $variantId, $qty, $barrier, $readyDir] = $argv;
    $ctx = json_decode(file_get_contents($ctxFile), true);
    $user = User::findOrFail($ctx['user_id']);
    app(\App\Support\OutletContext::class)->bind($user, $ctx['outlet_id']);
    auth()->setUser($user);

    touch("{$readyDir}/{$i}");
    $deadline = microtime(true) + 60;
    while (! file_exists($barrier) && microtime(true) < $deadline) {
        usleep(500);
    }

    $result = ['ok' => false, 'class' => null, 'code' => null, 'message' => null, 'retries' => 0];
    // TEMUAN DI LUAR 093: OrderService::generateOrderNumber() = COUNT(*) + 1 tidak atomik, jadi pesanan serentak bisa
    // menghasilkan nomor kembar (UniqueConstraintViolation) atau deadlock pada INSERT `orders`. Itu bug lama yang tidak
    // diubah di sini. Pekerja mengulang HANYA untuk dua kasus itu (dan menghitungnya), supaya bukti stok tidak tertutup;
    // deadlock pada kunci STOK tidak diulang di sini dan tetap tercatat sebagai galat tak terduga.
    for ($attempt = 0; $attempt < 12; $attempt++) {
        try {
            app(OrderService::class)->create([
                'session_id' => $ctx['session_id'], 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => (int) $variantId, 'qty' => (int) $qty]],
                'payments' => [['method' => 'cash', 'amount' => 5000000]],
            ], $user);
            $result['ok'] = true;
            $result['class'] = null;
            $result['message'] = null;
            break;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $orderNumberRace = ($e instanceof \Illuminate\Database\UniqueConstraintViolationException && str_contains($msg, 'order_number'))
                || ($e instanceof \Illuminate\Database\QueryException && str_contains($msg, '1213') && str_contains($msg, 'insert into `orders`'));
            if ($orderNumberRace) {
                $result['retries']++;
                usleep(random_int(2000, 25000));

                continue;
            }
            $result['class'] = class_basename($e);
            $result['message'] = substr($msg, 0, 1400);
            $result['code'] = $e instanceof \App\Exceptions\RecipeStockException ? 'insufficient_recipe_stock' : null;
            break;
        }
    }
    echo json_encode($result)."\n";
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------------
// Orkestrator
// ---------------------------------------------------------------------------------------------------------------------
$failures = [];
$check = function (bool $ok, string $what) use (&$failures) {
    echo ($ok ? '  ok   ' : '  GAGAL ').$what."\n";
    if (! $ok) {
        $failures[] = $what;
    }
};

function seed(): array
{
    Artisan::call('migrate:fresh', ['--force' => true]);
    $outlet = Outlet::factory()->create(['code' => 'WJR', 'name' => 'Warkop Race']);
    $user = User::factory()->create(['role' => 'cashier']);
    $session = CashierSession::factory()->create(['user_id' => $user->id, 'status' => 'open', 'outlet_id' => $outlet->id]);
    $category = Category::factory()->create();
    $make = function (string $name, array $product = []) use ($outlet, $category) {
        $p = Product::factory()->create(array_merge(['name' => $name, 'home_outlet_id' => $outlet->id, 'category_id' => $category->id], $product));

        return $p->variants()->create(['sku' => 'R'.Str::upper(Str::random(8)), 'variant_name' => 'Standard', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 0]);
    };
    $stock = fn (ProductVariant $v, int $qty) => $qty > 0 ? app(StockService::class)->applyMovement($v, $outlet->id, 'adjustment', $qty) : null;
    $recipe = function (ProductVariant $menu, array $lines) use ($user) {
        $r = Recipe::create(['product_id' => $menu->product_id, 'name' => 'Dasar', 'yield' => 1, 'effective_from' => now()->subDay()->toDateString(), 'created_by' => $user->id]);
        $r->variants()->sync([$menu->id]);
        $b = $r->blocks()->create(['variant_id' => $menu->id, 'modifier_option_id' => null, 'display_order' => 1]);
        foreach (array_values($lines) as $i => [$v, $q]) {
            $b->lines()->create(['recipe_id' => $r->id, 'variant_id' => $v->id, 'quantity' => $q, 'shrinkage_pct' => 0, 'display_order' => $i + 1]);
        }
    };

    return compact('outlet', 'user', 'session', 'make', 'stock', 'recipe');
}

/** @param list<array{variant: int, qty: int}> $jobs */
function race(array $world, array $jobs): array
{
    $dir = sys_get_temp_dir().'/ingredient_race_'.Str::random(8);
    mkdir($dir.'/ready', 0777, true);
    $ctx = $dir.'/ctx.json';
    file_put_contents($ctx, json_encode(['user_id' => $world['user']->id, 'session_id' => $world['session']->id, 'outlet_id' => $world['outlet']->id]));
    $barrier = $dir.'/go';

    $procs = [];
    foreach ($jobs as $i => $job) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', $ctx, (string) $i, (string) $job['variant'], (string) $job['qty'], $barrier, $dir.'/ready'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $_ENV + getenv());
        $procs[] = [$proc, $pipes];
    }
    $deadline = microtime(true) + 120;
    while (count(glob($dir.'/ready/*')) < count($jobs) && microtime(true) < $deadline) {
        usleep(20000);
    }
    touch($barrier);

    $results = [];
    foreach ($procs as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $line = collect(explode("\n", trim($out)))->last();
        $results[] = json_decode($line ?: '{}', true) ?: ['ok' => false, 'class' => 'WorkerCrash', 'message' => substr($err.$out, 0, 200)];
    }

    return $results;
}

function balance(ProductVariant $v, Outlet $o): int
{
    return (int) VariantOutletStock::where(['variant_id' => $v->id, 'outlet_id' => $o->id])->value('current_stock');
}

function summary(array $results): array
{
    return [
        'ok' => count(array_filter($results, fn ($r) => $r['ok'])),
        'refused' => count(array_filter($results, fn ($r) => ! $r['ok'] && in_array($r['class'], ['RecipeStockException', 'ValidationException', 'StockReservedException'], true))),
        'other' => array_values(array_filter($results, fn ($r) => ! $r['ok'] && ! in_array($r['class'], ['RecipeStockException', 'ValidationException', 'StockReservedException'], true))),
        'retries' => array_sum(array_column($results, 'retries')),
    ];
}

$N = 20;

echo "Skenario A: {$N} pesanan menu serentak, bahan produk tersisa 1\n";
$w = seed();
$telur = ($w['make'])('Telur', ['use_as_ingredient' => true]);
$menu = ($w['make'])('Nasi Telur');
($w['stock'])($telur, 1);
($w['recipe'])($menu, [[$telur, 1]]);
$s = summary(race($w, array_fill(0, $N, ['variant' => $menu->id, 'qty' => 1])));
$check($s['ok'] === 1, "tepat satu berhasil (ok={$s['ok']}, ditolak={$s['refused']})");
$check($s['other'] === [], 'tidak ada galat tak terduga/deadlock pada kunci stok: '.json_encode($s['other']));
echo "  info  pengulangan karena nomor pesanan kembar (bug lama, di luar 093): {$s['retries']}\n";
$check(balance($telur, $w['outlet']) === 0, 'saldo bahan tepat 0 (tidak minus): '.balance($telur, $w['outlet']));
$check(DB::table('stock_movements')->where('variant_id', $telur->id)->where('type', 'recipe_sale')->count() === 1, 'tepat satu pergerakan recipe_sale');

echo "Skenario B: penjualan menu berebut sisa terakhir dengan penjualan langsung bahan itu\n";
$w = seed();
$telur = ($w['make'])('Telur', ['use_as_ingredient' => true]);
$menu = ($w['make'])('Nasi Telur');
($w['stock'])($telur, 1);
($w['recipe'])($menu, [[$telur, 1]]);
$jobs = array_merge(array_fill(0, 10, ['variant' => $menu->id, 'qty' => 1]), array_fill(0, 10, ['variant' => $telur->id, 'qty' => 1]));
$s = summary(race($w, $jobs));
$check($s['ok'] === 1, "tepat satu dari 20 lolos (ok={$s['ok']})");
$check($s['other'] === [], 'tidak ada galat tak terduga/deadlock pada kunci stok: '.json_encode($s['other']));
echo "  info  pengulangan karena nomor pesanan kembar (bug lama, di luar 093): {$s['retries']}\n";
$check(balance($telur, $w['outlet']) === 0, 'saldo 0, tidak minus: '.balance($telur, $w['outlet']));

echo "Skenario C: stok 5, {$N} pesanan serentak\n";
$w = seed();
$telur = ($w['make'])('Telur', ['use_as_ingredient' => true]);
$menu = ($w['make'])('Nasi Telur');
($w['stock'])($telur, 5);
($w['recipe'])($menu, [[$telur, 1]]);
$s = summary(race($w, array_fill(0, $N, ['variant' => $menu->id, 'qty' => 1])));
$check($s['ok'] === 5, "tepat lima berhasil (ok={$s['ok']})");
$check($s['other'] === [], 'tidak ada galat tak terduga/deadlock pada kunci stok: '.json_encode($s['other']));
echo "  info  pengulangan karena nomor pesanan kembar (bug lama, di luar 093): {$s['retries']}\n";
$check(balance($telur, $w['outlet']) === 0, 'saldo 0: '.balance($telur, $w['outlet']));

echo "Skenario D: dua menu dengan bahan yang sama dalam urutan baris TERBALIK (potensi deadlock)\n";
$w = seed();
$a = ($w['make'])('Bahan A', ['use_as_ingredient' => true]);
$b = ($w['make'])('Bahan B', ['use_as_ingredient' => true]);
$m1 = ($w['make'])('Menu 1');
$m2 = ($w['make'])('Menu 2');
($w['stock'])($a, 30);
($w['stock'])($b, 30);
($w['recipe'])($m1, [[$a, 1], [$b, 1]]);
($w['recipe'])($m2, [[$b, 1], [$a, 1]]);
$jobs = [];
foreach (range(0, 39) as $i) {
    $jobs[] = ['variant' => $i % 2 ? $m1->id : $m2->id, 'qty' => 1];
}
$s = summary(race($w, $jobs));
$deducted = 30 - balance($a, $w['outlet']);
$check($s['other'] === [], 'tidak ada deadlock/galat tak terduga pada kunci stok: '.json_encode($s['other']));
echo "  info  pengulangan karena nomor pesanan kembar (bug lama, di luar 093): {$s['retries']}\n";
$check($s['ok'] === 30, "tepat 30 berhasil dari 40 (ok={$s['ok']})");
$check($deducted === $s['ok'] && 30 - balance($b, $w['outlet']) === $s['ok'], "pemotongan = jumlah sukses (A dipotong {$deducted})");
$check(balance($a, $w['outlet']) >= 0 && balance($b, $w['outlet']) >= 0, 'tidak ada saldo minus');

echo "\n".($failures === [] ? "SEMUA SKENARIO LULUS\n" : 'GAGAL: '.count($failures)." pemeriksaan\n");
exit($failures === [] ? 0 : 1);
