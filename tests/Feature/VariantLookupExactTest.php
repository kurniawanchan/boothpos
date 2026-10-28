<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 049 (US5) — `GET /variants/lookup?exact=1`: satu-satunya cara POS menyelesaikan kode hasil pindai.
 * Pencarian LIKE biasa dibatasi 40 baris dan mengurut menurut SKU, jadi "cari yang persis sama di hasilnya"
 * di klien bisa meleset untuk awalan pendek; pencarian persis menjawab satu baris atau tidak sama sekali.
 */
class VariantLookupExactTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'name' => 'MCYT',
        ]);
        $this->variant = $product->variants()->create(['sku' => 'SPF-KC-MCYT-3', 'variant_name' => 'Slippery 5cm', 'sell_price' => 20000, 'current_stock' => 4]);
        // SKU yang diawali SKU di atas: pencarian LIKE biasa akan mengembalikan keduanya.
        $product->variants()->create(['sku' => 'SPF-KC-MCYT-30', 'variant_name' => 'Lain', 'sell_price' => 21000, 'current_stock' => 1]);
    }

    private function exact(string $q): array
    {
        return $this->getJson('/api/v1/variants/lookup?exact=1&q='.rawurlencode($q))->assertOk()->json('data');
    }

    public function test_an_exact_sku_returns_exactly_that_variant_in_the_usual_shape(): void
    {
        $rows = $this->exact('SPF-KC-MCYT-3');

        $this->assertCount(1, $rows);
        $this->assertSame($this->variant->id, $rows[0]['variant_id']);
        $this->assertSame(['variant_id', 'sku', 'label', 'artist_name', 'category_name', 'sell_price', 'current_stock', 'is_preorder', 'image_url'], array_keys($rows[0]));
        $this->assertSame('20000.00', $rows[0]['sell_price']);
        $this->assertSame(4, $rows[0]['current_stock']);
    }

    public function test_the_match_ignores_letter_case_and_surrounding_spaces(): void
    {
        $this->assertSame([$this->variant->id], array_column($this->exact('  spf-kc-mcyt-3  '), 'variant_id'));
    }

    public function test_a_partial_sku_a_variant_name_or_a_product_name_matches_nothing(): void
    {
        $this->assertSame([], $this->exact('SPF-KC-MCYT'));   // awalan
        $this->assertSame([], $this->exact('MCYT-3'));        // potongan
        $this->assertSame([], $this->exact('Slippery 5cm'));  // nama varian
        $this->assertSame([], $this->exact('MCYT'));          // nama produk
        $this->assertSame([], $this->exact('%'));             // wildcard bukan karakter "semua"
    }

    public function test_an_empty_code_returns_nothing(): void
    {
        $this->assertSame([], $this->exact(''));
        $this->assertSame([], $this->exact('   '));
    }

    public function test_an_inactive_variant_is_not_resolved(): void
    {
        $this->variant->update(['is_active' => false]);

        $this->assertSame([], $this->exact('SPF-KC-MCYT-3'));
    }

    public function test_without_exact_the_search_behaves_exactly_as_before(): void
    {
        $rows = $this->getJson('/api/v1/variants/lookup?q='.rawurlencode('SPF-KC-MCYT-3'))->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['SPF-KC-MCYT-3', 'SPF-KC-MCYT-30'], array_column($rows, 'sku'));
    }

    public function test_every_catalog_code_resolves_to_its_own_variant_through_exact_lookup(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        foreach ($this->getJson('/api/v1/catalog')->assertOk()->json('sellers.*.variants.*') as $row) {
            $this->assertSame([$row['variant_id']], array_column($this->exact($row['code']), 'variant_id'), "kode {$row['code']} harus menemukan varian yang sama");
        }
    }
}
