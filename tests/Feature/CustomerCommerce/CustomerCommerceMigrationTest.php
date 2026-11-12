<?php

namespace Tests\Feature\CustomerCommerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §4) — migrasi harus (a) bisa dibatalkan bersih
 * dan (b) mengisi slug baris LAMA di kedua mode data, termasuk baris yang
 * sudah dihapus lunak, sebelum kolomnya dijadikan NOT NULL.
 *
 * Test ini SENGAJA menjalankan DDL sungguhan (rollback lalu migrate): MySQL
 * meng-commit transaksi RefreshDatabase secara implisit pada DDL, jadi apa pun
 * yang ditulis di sini benar-benar tersimpan. Karena itu tearDown() membangun
 * ulang skema (`migrate:fresh`) supaya test berikutnya mulai dari basis data
 * bersih yang sama seperti biasanya. Penjaga nama basis data di
 * Tests\TestCase tetap berlaku (hanya basis data *test*).
 *
 * Rollback dihitung dari jumlah berkas migrasi mulai `2026_11_27_000001`,
 * bukan angka tetap: irisan berikutnya boleh menambah migrasi sesudahnya dan
 * `down()` miliknya ikut teruji di sini.
 */
class CustomerCommerceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_MIGRATION = '2026_11_27_000001';

    private const TABLES = [
        'customer_accounts', 'customer_addresses', 'customer_orders',
        'customer_order_items', 'customer_order_shipments', 'customer_order_status_events',
    ];

    protected function tearDown(): void
    {
        // Skema dan data yang ter-commit dikembalikan ke keadaan awal suite.
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    private function steps(): int
    {
        return count(array_filter(
            scandir(database_path('migrations')),
            fn (string $file) => str_ends_with($file, '.php') && strcmp(substr($file, 0, 17), self::FIRST_MIGRATION) >= 0,
        ));
    }

    private function legacyProduct(int $artist, int $category, string $mode, string $prefix, string $name, bool $deleted = false): int
    {
        return DB::table('products')->insertGetId([
            'artist_id' => $artist, 'category_id' => $category, 'code_prefix' => $prefix, 'product_segment' => 'ABC',
            'name' => $name, 'data_mode' => $mode, 'deleted_at' => $deleted ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_migrations_roll_back_cleanly_and_backfill_slugs_for_existing_rows(): void
    {
        $this->assertGreaterThanOrEqual(4, $this->steps());

        $this->artisan('migrate:rollback', ['--step' => $this->steps()])->assertSuccessful();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} harus hilang setelah rollback.");
        }
        $this->assertFalse(Schema::hasColumn('products', 'slug'));
        $this->assertFalse(Schema::hasColumn('products', 'published_at'));
        $this->assertFalse(Schema::hasColumn('product_variants', 'published_at'));

        // Baris "lama" (sebelum fitur ini): dua mode, nama kembar, baris terhapus lunak, nama tanpa huruf.
        $ids = [];
        foreach (['live' => ['LA1', 'L1'], 'demo' => ['DA1', 'D1']] as $mode => [$artistCode, $categoryCode]) {
            $artist = DB::table('artists')->insertGetId(['code' => $artistCode, 'name' => "Seller {$mode}", 'data_mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);
            $category = DB::table('categories')->insertGetId(['code' => $categoryCode, 'name' => "Kategori {$mode}", 'data_mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);

            $ids[$mode] = [
                'first' => $this->legacyProduct($artist, $category, $mode, "{$artistCode}-{$categoryCode}-AAA", 'Gantungan Kunci'),
                'deleted' => $this->legacyProduct($artist, $category, $mode, "{$artistCode}-{$categoryCode}-BBB", 'Gantungan Kunci', deleted: true),
                'third' => $this->legacyProduct($artist, $category, $mode, "{$artistCode}-{$categoryCode}-CCC", 'gantungan   KUNCI'),
                'symbols' => $this->legacyProduct($artist, $category, $mode, "{$artistCode}-{$categoryCode}-DDD", '!!!'),
                'symbols2' => $this->legacyProduct($artist, $category, $mode, "{$artistCode}-{$categoryCode}-EEE", '???'),
            ];
        }

        $this->artisan('migrate')->assertSuccessful();

        $slug = fn (int $id) => DB::table('products')->where('id', $id)->value('slug');

        foreach (['live', 'demo'] as $mode) {
            // Urut id di dalam modenya sendiri; baris terhapus lunak ikut memakai nomor.
            $this->assertSame('gantungan-kunci', $slug($ids[$mode]['first']), $mode);
            $this->assertSame('gantungan-kunci-2', $slug($ids[$mode]['deleted']), $mode);
            $this->assertSame('gantungan-kunci-3', $slug($ids[$mode]['third']), $mode);
            $this->assertSame('produk', $slug($ids[$mode]['symbols']), $mode);
            $this->assertSame('produk-2', $slug($ids[$mode]['symbols2']), $mode);
        }

        // Baris lama tetap TIDAK terbit: tidak ada yang tampil di etalase tanpa tindakan staf.
        $this->assertSame(0, DB::table('products')->whereNotNull('published_at')->count());

        $column = collect(Schema::getColumns('products'))->firstWhere('name', 'slug');
        $this->assertFalse($column['nullable'], 'slug harus NOT NULL setelah backfill.');

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} harus ada lagi setelah migrate.");
        }
    }
}
