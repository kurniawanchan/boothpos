<?php

namespace Tests\Feature\CustomerCommerce;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ModeGate;
use App\Support\ProductSlug;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SakanaFridgeDemoSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer commerce (CONTRACT §4) — slug produk dan kolom publikasi.
 *
 * Slug adalah pengenal publik produk di etalase (`/storefront/products/{slug}`):
 * dibuat di hook `creating` supaya SEMUA jalur pembuatan (controller, impor
 * Excel, seeder, factory) mendapatkannya, unik per mode data, dan tidak berubah
 * saat produk diganti nama (tautan dan cache aplikasi Android harus tetap sah).
 */
class ProductSlugAndPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attrs = []): Product
    {
        return Product::factory()->create($attrs);
    }

    public function test_a_new_product_gets_a_slug_from_its_name(): void
    {
        $this->assertSame('gantungan-kunci-sakura', $this->product(['name' => 'Gantungan Kunci Sakura'])->slug);
    }

    public function test_a_name_without_usable_characters_falls_back_to_produk(): void
    {
        $this->assertSame('produk', $this->product(['name' => '!!!'])->slug);
        $this->assertSame('produk-2', $this->product(['name' => '???'])->slug);
    }

    public function test_duplicate_names_are_suffixed_and_soft_deleted_rows_still_count(): void
    {
        $first = $this->product(['name' => 'Poster Yuki']);
        $second = $this->product(['name' => 'Poster Yuki']);
        $second->delete(); // soft delete: slug-nya tetap terpakai (indeks unik tidak mengenal deleted_at)
        $third = $this->product(['name' => 'POSTER   yuki']);

        $this->assertSame('poster-yuki', $first->slug);
        $this->assertSame('poster-yuki-2', $second->slug);
        $this->assertSame('poster-yuki-3', $third->slug);
    }

    public function test_slugs_are_unique_per_data_mode_not_globally(): void
    {
        $live = $this->product(['name' => 'Stiker Neko']);
        $demo = ModeGate::runAs('demo', fn () => $this->product(['name' => 'Stiker Neko']));
        $demoAgain = ModeGate::runAs('demo', fn () => $this->product(['name' => 'Stiker Neko']));

        $this->assertSame('live', $live->data_mode);
        $this->assertSame('demo', $demo->data_mode);
        $this->assertSame('stiker-neko', $live->slug);
        $this->assertSame('stiker-neko', $demo->slug, 'Mode lain tidak ikut menghabiskan slug.');
        $this->assertSame('stiker-neko-2', $demoAgain->slug);
    }

    public function test_the_database_refuses_a_duplicate_slug_inside_one_mode(): void
    {
        $product = $this->product(['name' => 'Pin Enamel']);
        $row = (array) DB::table('products')->where('id', $product->id)->first();
        unset($row['id']);
        $row['code_prefix'] = 'ZZZ-ZZ-DUP';

        // Mode lain boleh memakai slug yang sama (klon LIVE → DEMO menyalin slug apa adanya)…
        DB::table('products')->insert(['data_mode' => 'demo'] + $row);
        $this->assertSame(2, DB::table('products')->where('slug', 'pin-enamel')->count());

        // …tetapi tidak di mode yang sama.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('products')->insert(['code_prefix' => 'ZZZ-ZZ-DU2'] + $row);
    }

    public function test_a_long_name_is_truncated_to_fit_the_column(): void
    {
        $product = $this->product(['name' => str_repeat('panjang ', 18).'x']); // 145 karakter (kolom nama 150)

        $this->assertLessThanOrEqual(140, strlen($product->slug));
        $this->assertStringEndsNotWith('-', $product->slug);
    }

    public function test_renaming_a_product_through_the_api_keeps_its_slug_and_ignores_a_client_slug(): void
    {
        $product = $this->product(['name' => 'Kaos Lama']);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->putJson("/api/v1/products/{$product->id}", ['name' => 'Kaos Baru', 'slug' => 'dibajak'])
            ->assertOk()
            ->assertJsonPath('name', 'Kaos Baru');

        $product->refresh();
        $this->assertSame('Kaos Baru', $product->name);
        $this->assertSame('kaos-lama', $product->slug, 'Slug tidak berubah saat ganti nama maupun lewat input klien.');
    }

    public function test_slug_and_published_at_are_not_mass_assignable(): void
    {
        $product = $this->product(['name' => 'Tote Bag']);

        $product->update(['slug' => 'lain', 'published_at' => now()]);
        $product->refresh();

        $this->assertSame('tote-bag', $product->slug);
        $this->assertNull($product->published_at);
    }

    public function test_a_product_created_through_the_staff_api_gets_a_slug_and_stays_unpublished(): void
    {
        $artist = Artist::factory()->create(['code' => 'RYU']);
        $category = Category::factory()->create(['code' => 'KY']);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $id = $this->postJson('/api/v1/products', [
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'name' => 'Keychain Sakura',
            'slug' => 'pilihan-klien',
            'published_at' => now()->toIso8601String(),
            'variants' => [['variant_name' => 'Varian A', 'sell_price' => 25000, 'published_at' => now()->toIso8601String()]],
        ])->assertCreated()->json('id');

        $product = Product::with('variants')->findOrFail($id);
        $this->assertSame('keychain-sakura', $product->slug);
        $this->assertNull($product->published_at, 'Produk baru tidak pernah otomatis tampil di etalase.');
        $this->assertNull($product->variants->first()->published_at);
    }

    public function test_products_created_by_the_master_data_excel_import_get_a_slug(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        // Templat bawaan diimpor apa adanya (jalur `new Product(...)->save()` di MasterDataImportService).
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';

        try {
            file_put_contents($path, $this->get('/api/v1/imports/master-data/template')->streamedContent());

            $this->post(
                '/api/v1/imports/master-data',
                ['file' => new UploadedFile($path, 'template-impor-master-data.xlsx', null, null, true)],
                ['Accept' => 'application/json'],
            )->assertOk()->assertJsonPath('applied', true)->assertJsonPath('errors', []);
        } finally {
            @unlink($path);
        }

        $products = Product::all();
        $this->assertNotEmpty($products);

        foreach ($products as $product) {
            $this->assertStringStartsWith(ProductSlug::base($product->name), $product->slug);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $product->slug);
            $this->assertNull($product->published_at);
        }
        $this->assertSame($products->count(), $products->pluck('slug')->unique()->count());
    }

    public function test_the_demo_seeder_still_creates_products_with_unique_slugs_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class); // seeder demo butuh pengguna bawaan
        $this->seed(SakanaFridgeDemoSeeder::class);

        $rows = DB::table('products')->get(['id', 'slug', 'data_mode', 'published_at']);
        $this->assertGreaterThan(0, $rows->count());
        $this->assertSame(['demo'], $rows->pluck('data_mode')->unique()->values()->all());
        $this->assertSame($rows->count(), $rows->pluck('slug')->unique()->count());
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $row->slug);
        }
        $this->assertSame(0, $rows->whereNotNull('published_at')->count(), 'Seeder tidak menerbitkan apa pun ke etalase.');

        // Dijalankan ulang: produk yang sudah ada tidak dibuat lagi, slug-nya tidak berubah.
        $this->seed(SakanaFridgeDemoSeeder::class);

        $this->assertEquals(
            $rows->pluck('slug', 'id')->all(),
            DB::table('products')->pluck('slug', 'id')->all(),
        );
    }

    public function test_published_at_is_null_by_default_and_cast_to_a_date_on_both_models(): void
    {
        $product = $this->product();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->assertNull($product->fresh()->published_at);
        $this->assertNull($variant->fresh()->published_at);

        $at = Carbon::parse('2026-11-11 10:00:00');
        $product->forceFill(['published_at' => $at])->save();
        $variant->forceFill(['published_at' => $at])->save();

        $this->assertInstanceOf(Carbon::class, $product->fresh()->published_at);
        $this->assertTrue($at->equalTo($product->fresh()->published_at));
        $this->assertTrue($at->equalTo($variant->fresh()->published_at));
    }
}
