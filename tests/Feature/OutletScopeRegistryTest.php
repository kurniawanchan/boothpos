<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToOutlet;
use App\Support\OutletScopeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 045 Stage 2 (T033/T036) — daftar tunggal tabel/kueri ber-outlet tidak boleh basi.
 */
class OutletScopeRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_model_uses_the_outlet_trait_and_its_column_is_required(): void
    {
        foreach (OutletScopeRegistry::MODELS as $model) {
            $this->assertContains(BelongsToOutlet::class, class_uses_recursive($model), "{$model} must use BelongsToOutlet");
        }

        foreach (OutletScopeRegistry::tables() as $table) {
            $column = DB::selectOne('SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, 'outlet_id']);
            $this->assertNotNull($column, "{$table}.outlet_id must exist");
            $this->assertSame('NO', $column->n, "{$table}.outlet_id must be NOT NULL");
        }
    }

    public function test_every_model_with_the_trait_is_registered(): void
    {
        $found = [];
        foreach (glob(base_path('app/Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (class_exists($class) && in_array(BelongsToOutlet::class, class_uses_recursive($class), true)) {
                $found[] = $class;
            }
        }
        $this->assertEqualsCanonicalizing(OutletScopeRegistry::MODELS, $found, 'a model uses BelongsToOutlet but is missing from OutletScopeRegistry::MODELS (or vice versa)');
    }

    public function test_every_file_with_a_raw_query_on_an_outlet_table_is_registered_and_filtered_ones_use_outlet_filter(): void
    {
        $tables = implode('|', OutletScopeRegistry::tables());
        $pattern = "/(DB::table\\('({$tables}|order_items)'|->from\\('({$tables})'|join\\('({$tables})')/";
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        $withRaw = [];
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Console/')) {
                continue;
            }
            if (preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
                $withRaw[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(OutletScopeRegistry::RAW_QUERY_FILES), $withRaw, 'register every raw query file in OutletScopeRegistry::RAW_QUERY_FILES (filtered or exempt with a reason)');

        foreach (OutletScopeRegistry::RAW_QUERY_FILES as $path => $decision) {
            if ($decision === 'filtered') {
                $this->assertStringContainsString('OutletFilter', (string) file_get_contents(base_path($path)), "{$path} is declared filtered but never calls OutletFilter");
            } else {
                $this->assertStringStartsWith('exempt: ', $decision, "{$path} needs a reason");
            }
        }
    }

    public function test_every_table_with_an_outlet_id_column_is_registered_with_a_reason(): void
    {
        $tables = collect(DB::select("SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'outlet_id'"))
            ->pluck('t')->sort()->values()->all();

        $registered = collect(OutletScopeRegistry::tables())->merge(array_keys(OutletScopeRegistry::OTHER_TABLES))->sort()->values()->all();

        $this->assertSame($registered, $tables, 'a table with an outlet_id column is not in OutletScopeRegistry (MODELS or OTHER_TABLES)');
        foreach (OutletScopeRegistry::OTHER_TABLES as $table => $reason) {
            $this->assertNotSame('', trim($reason), "{$table} needs a reason");
        }
    }
}
