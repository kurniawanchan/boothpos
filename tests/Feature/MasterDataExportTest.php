<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\MasterDataSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MasterDataExportTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function seedCatalog(): void
    {
        $artist = Artist::factory()->create(['code' => 'RYU', 'name' => 'Ryu Illustration']);
        $category = Category::factory()->create(['code' => 'KY', 'name' => 'Keychain']);
        $product = Product::factory()->create([
            'artist_id' => $artist->id,
            'category_id' => $category->id,
            'code_prefix' => 'RYUKYSAK',
            'product_segment' => 'SAK',
            'name' => 'Keychain Sakura',
        ]);
        $product->variants()->create([
            'sku' => 'RYUKYSAK0001',
            'variant_name' => 'Standard',
            'cost_price' => 10000,
            'sell_price' => 25000,
            'current_stock' => 12,
        ]);
    }

    /**
     * Berkas benar-benar dibaca ulang lewat PhpSpreadsheet — bukan sekadar
     * memeriksa status 200 dan panjang byte, karena export yang rusak tetap
     * menghasilkan berkas berukuran non-nol (bug itu pernah terjadi di
     * ReportController::export).
     */
    private function readDownload(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);

        $sheets = [];
        foreach ($spreadsheet->getAllSheets() as $worksheet) {
            $sheets[$worksheet->getTitle()] = $worksheet->toArray();
        }

        unlink($path);

        return $sheets;
    }

    public function test_products_export_is_a_readable_file_with_canonical_sheet_name(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $sheets = $this->readDownload('/api/v1/exports/products');

        $this->assertArrayHasKey(MasterDataSheets::PRODUCTS, $sheets);

        $rows = $sheets[MasterDataSheets::PRODUCTS];

        $this->assertSame(MasterDataSheets::headings(MasterDataSheets::PRODUCTS), $rows[0]);

        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('RYUKYSAK0001', $row['sku']);
        $this->assertSame('RYU', $row['artist_code']);
        $this->assertSame('KY', $row['category_code']);
        $this->assertSame('Keychain Sakura', $row['product_name']);
        $this->assertSame('Standard', $row['variant_name']);
        $this->assertEquals(25000, $row['sell_price']);
    }

    public function test_stock_export_lists_every_variant_with_its_current_stock(): void
    {
        $this->actingAsRole('inventory');
        $this->seedCatalog();

        $sheets = $this->readDownload('/api/v1/exports/stock');

        $rows = $sheets[MasterDataSheets::STOCK];
        $this->assertSame(['sku', 'current_stock', 'reason'], $rows[0]);

        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('RYUKYSAK0001', $row['sku']);
        $this->assertEquals(12, $row['current_stock']);
    }

    public function test_artists_and_categories_export_use_their_own_columns(): void
    {
        $this->actingAsRole('owner');
        $this->seedCatalog();

        $artists = $this->readDownload('/api/v1/exports/artists')[MasterDataSheets::ARTISTS];
        $this->assertSame(MasterDataSheets::headings(MasterDataSheets::ARTISTS), $artists[0]);
        $this->assertSame('RYU', array_combine($artists[0], $artists[1])['code']);

        $categories = $this->readDownload('/api/v1/exports/categories')[MasterDataSheets::CATEGORIES];
        $this->assertSame(MasterDataSheets::headings(MasterDataSheets::CATEGORIES), $categories[0]);
        $this->assertSame('KY', array_combine($categories[0], $categories[1])['code']);
    }

    public function test_export_is_gated_to_master_data_roles(): void
    {
        $this->actingAsRole('cashier');
        $this->seedCatalog();

        $this->getJson('/api/v1/exports/products')->assertStatus(403);
        $this->getJson('/api/v1/exports/stock')->assertStatus(403);
    }

    public function test_unknown_export_entity_is_rejected_by_the_route(): void
    {
        $this->actingAsRole('owner');

        $this->getJson('/api/v1/exports/customers')->assertStatus(404);
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/v1/exports/products')->assertStatus(401);
    }
}
