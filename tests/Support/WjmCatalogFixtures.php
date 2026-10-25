<?php

namespace Tests\Support;

use App\Services\WjmCatalogSeedService;
use App\Support\ModeGate;
use App\Support\WjmCatalog;
use App\Support\WjmCatalogSeedReport;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\WarkopMenuDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * 068 — pembantu tes pengisian katalog WJM. Katalog mini di tests/Support/wjm-catalog/ sengaja generik (bukan
 * data pelanggan) tetapi memuat setiap bentuk yang perlu diuji: multi-varian, kombinasi dengan harga khusus,
 * tabrakan segmen "Roti Bakar …", harga 0, kit, foto ada/terpotong/kosong, grup pilihan bernama "Rasa" (sama
 * dengan grup contoh WarkopMenuDemoSeeder), dan pengecualian.
 */
trait WjmCatalogFixtures
{
    /** Tabel yang boleh disentuh pengisian katalog; dipakai asersi "tanpa perubahan". */
    private const WJM_TABLES = [
        'outlets', 'stations', 'categories', 'products', 'product_variants', 'modifier_groups', 'modifier_options',
        'modifier_group_product', 'outlet_item_settings', 'price_history', 'activity_logs',
        'variant_outlet_stock', 'stock_movements',
    ];

    /** `wjm-catalog` = outlet pertama (katalog mini 068); `wjm2-catalog` = outlet kedua (069). */
    protected function wjmFixtureDir(string $fixture = 'wjm-catalog'): string
    {
        return base_path('tests/Support/'.$fixture);
    }

    /**
     * Tanpa mutasi: jalur katalog mini. Dengan mutasi: salinan folder katalog mini (beserta foto) di folder
     * sementara, dengan JSON hasil `$mutate($data)`.
     */
    protected function wjmCatalogPath(?callable $mutate = null, string $fixture = 'wjm-catalog'): string
    {
        if ($mutate === null) {
            return $this->wjmFixtureDir($fixture).'/catalog.json';
        }

        $dir = sys_get_temp_dir().'/wjm-'.uniqid('', true);
        File::copyDirectory($this->wjmFixtureDir($fixture), $dir);
        $data = json_decode(File::get($dir.'/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        File::put($dir.'/catalog.json', json_encode($mutate($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $dir.'/catalog.json';
    }

    /** @return array<string, WjmCatalogSeedReport> */
    protected function wjmRun(array $modes = ['live'], ?string $outlet = null, bool $dryRun = false, ?string $path = null): array
    {
        return app(WjmCatalogSeedService::class)->run(WjmCatalog::fromFile($path ?? $this->wjmCatalogPath()), $modes, $outlet, $dryRun);
    }

    protected function inMode(string $mode, callable $fn): mixed
    {
        return ModeGate::runAs($mode, $fn);
    }

    /** @return array<string, int> jumlah baris lintas mode per tabel */
    protected function rowCounts(): array
    {
        return collect(self::WJM_TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /** Menu contoh "Warkop WJM 3" di DEMO (outlet WJ3, 32 item, grup "Rasa" berisi Cokelat/Keju/Srikaya/Kacang). */
    protected function placeholderDemoMenu(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(WarkopMenuDemoSeeder::class);
    }
}
