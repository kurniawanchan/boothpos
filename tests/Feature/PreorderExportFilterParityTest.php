<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 066-preorder-filter-export-columns (US4) — ekspor berisi PERSIS baris yang ada di layar untuk kombinasi filter apa pun.
 * Dulu ekspor memakai rantai filter salinan yang lebih sempit (pencarian hanya nama pelanggan, tanpa artist_id);
 * kini list dan ekspor memakai satu scope (Preorder::scopeMatchingListFilters). Tes ini lewat HTTP sungguhan supaya
 * daftar kunci yang dipercaya controller ikut teruji, dan membaca kolom pertama berkas (`preorder_number`).
 */
class PreorderExportFilterParityTest extends TestCase
{
    use RefreshDatabase;

    private Customer $siti;

    private Customer $budi;

    private Category $catX;

    private Category $catY;

    private Artist $sellerOne;

    private Artist $sellerTwo;

    /** @var array<string, Preorder> */
    private array $po = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->siti = Customer::factory()->create(['name' => 'Siti Amalia']);
        $this->budi = Customer::factory()->create(['name' => 'Budi Santoso']);
        $this->catX = Category::factory()->create();
        $this->catY = Category::factory()->create();
        $this->sellerOne = Artist::factory()->create();
        $this->sellerTwo = Artist::factory()->create();

        // Empat pre-order, tiap kombinasi (pelanggan, kategori, penjual, status) berbeda.
        $this->po['p1'] = $this->makePreorder($this->siti, 'PAR-A', $this->catX, $this->sellerOne, 'dp_paid');
        $this->po['p2'] = $this->makePreorder($this->siti, 'PAR-B', $this->catY, $this->sellerTwo, 'ordered');
        $this->po['p3'] = $this->makePreorder($this->budi, 'PAR-C', $this->catX, $this->sellerTwo, 'dp_paid');
        $this->po['p4'] = $this->makePreorder($this->budi, 'PAR-D', $this->catY, $this->sellerOne, 'ordered');
    }

    private function makePreorder(Customer $customer, string $sku, Category $category, Artist $seller, string $status): Preorder
    {
        $product = Product::factory()->create(['artist_id' => $seller->id, 'category_id' => $category->id, 'is_preorder' => true]);
        /** @var ProductVariant $variant */
        $variant = $product->variants()->create(['sku' => $sku, 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);

        $preorder = Preorder::create([
            'preorder_number' => 'PO-20261110-'.substr($sku, -1).'000',
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'fulfillment' => 'pickup',
            'subtotal' => 100000,
            'total_amount' => 100000,
        ]);
        $preorder->items()->create([
            'variant_id' => $variant->id, 'artist_id' => $seller->id, 'sku_snapshot' => $sku,
            'name_snapshot' => 'Item '.$sku, 'qty' => 1, 'cost_price' => 50000, 'sell_price' => 100000, 'line_total' => 100000,
        ]);

        return $preorder;
    }

    /** Nomor PO dari GET /preorders (layar). */
    private function listNumbers(string $query): array
    {
        $numbers = collect($this->getJson('/api/v1/preorders?per_page=100&'.$query)->assertOk()->json('data'))
            ->pluck('preorder_number')->all();
        sort($numbers);

        return $numbers;
    }

    /** Nomor PO dari berkas ekspor (kolom pertama, tanpa baris judul). */
    private function exportNumbers(string $query): array
    {
        $res = $this->get('/api/v1/preorders/export?'.$query)->assertOk();
        $path = $res->baseResponse->getFile()->getPathname();
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        $this->assertSame('preorder_number', $rows[0][0]);

        $numbers = collect(array_slice($rows, 1))->pluck(0)->filter(fn ($v) => $v !== null && $v !== '')->values()->all();
        sort($numbers);

        return $numbers;
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function filterCases(): array
    {
        return [
            'customer (array)' => ['SITI', 2],
            'category' => ['CAT_X', 2],
            'seller' => ['SELLER_ONE', 2],
            'status' => ['status[]=dp_paid', 2],
            'search by PO number' => ['search=PO-20261110-A000', 1],
            'search by SKU / item name' => ['search=PAR-C', 1],
            'search by customer name' => ['search=budi', 2],
            'all filters combined' => ['COMBINED', 1],
        ];
    }

    private function resolve(string $query): string
    {
        return match ($query) {
            'SITI' => 'customer_id[]='.$this->siti->id,
            'CAT_X' => 'category_id[]='.$this->catX->id,
            'SELLER_ONE' => 'artist_id[]='.$this->sellerOne->id,
            'COMBINED' => implode('&', [
                'customer_id[]='.$this->siti->id, 'category_id[]='.$this->catX->id,
                'artist_id[]='.$this->sellerOne->id, 'status[]=dp_paid', 'search=PAR-A',
            ]),
            default => $query,
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filterCases')]
    public function test_the_export_has_exactly_the_rows_the_list_shows(string $case, int $expectedCount): void
    {
        $query = $this->resolve($case);

        $onScreen = $this->listNumbers($query);

        $this->assertCount($expectedCount, $onScreen, 'data uji harus menghasilkan himpunan bagian, bukan semua/kosong');
        $this->assertSame($onScreen, $this->exportNumbers($query));
    }

    public function test_without_filters_every_preorder_of_the_active_mode_is_exported(): void
    {
        $demoCustomer = ModeGate::runAs('demo', fn () => Customer::factory()->create());
        ModeGate::runAs('demo', fn () => $this->makePreorder($demoCustomer, 'PAR-Z', $this->catX, $this->sellerOne, 'ordered'));

        $exported = $this->exportNumbers('');

        $this->assertCount(4, $exported);
        $this->assertSame($this->listNumbers(''), $exported);
        $this->assertNotContains('PO-20261110-Z000', $exported);
    }

    public function test_legacy_scalar_customer_id_still_filters_the_export(): void
    {
        $this->assertSame($this->listNumbers('customer_id='.$this->siti->id), $this->exportNumbers('customer_id='.$this->siti->id));
        $this->assertCount(2, $this->exportNumbers('customer_id='.$this->siti->id));
    }

    public function test_the_export_only_date_range_still_narrows(): void
    {
        DB::table('preorders')->where('id', $this->po['p1']->id)->update(['created_at' => '2026-01-01 10:00:00']);

        $this->assertCount(3, $this->exportNumbers('date_from=2026-06-01'));
        $this->assertSame(['PO-20261110-A000'], $this->exportNumbers('date_to=2026-02-01'));
    }

    public function test_a_cashier_still_cannot_export(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->get('/api/v1/preorders/export?customer_id[]='.$this->siti->id)->assertForbidden();
    }
}
