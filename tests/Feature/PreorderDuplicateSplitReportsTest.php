<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\PreorderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PreorderExportImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 027-preorder-duplicate-split (Polish, FR-024/FR-025/SC-006) — pre-order hasil
 * duplikat/split diperlakukan seperti pre-order biasa oleh list, ringkasan,
 * ekspor, invoice, dan laporan, dan tidak ada pendapatan yang terhitung ganda.
 */
class PreorderDuplicateSplitReportsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private ProductVariant $a;

    private ProductVariant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $category = Category::factory()->create();
        $make = fn (string $sku, float $price) => Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'category_id' => $category->id, 'is_preorder' => true,
        ])->variants()->create(['sku' => $sku, 'sell_price' => $price, 'cost_price' => $price / 2, 'current_stock' => 0]);

        $this->a = $make('RPRT0001', 100000);
        $this->b = $make('RPRT0002', 50000);
        $this->customer = Customer::factory()->create(['name' => 'Pelanggan Laporan']);
    }

    private function order(array $overrides = []): array
    {
        return $this->postJson('/api/v1/preorders', array_merge([
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->a->id, 'qty' => 2], ['variant_id' => $this->b->id, 'qty' => 1]],
        ], $overrides))->assertCreated()->json();
    }

    public function test_copies_and_split_results_behave_like_ordinary_pre_orders_everywhere(): void
    {
        $source = $this->order();
        $copy = $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => [$source['id']]])
            ->assertOk()->json('data.0.preorder');
        $bItem = PreorderItem::where('preorder_id', $source['id'])->where('variant_id', $this->b->id)->value('id');
        $split = $this->postJson("/api/v1/preorders/{$source['id']}/split", [
            'mode' => 'items', 'items' => [['item_id' => $bItem, 'qty' => 1]],
        ])->assertCreated()->json('created.0');

        // list: pencarian nomor, pencarian pelanggan, filter status, urutan nomor
        $this->assertCount(3, $this->getJson('/api/v1/preorders?search=Pelanggan')->json('data'));
        $found = $this->getJson('/api/v1/preorders?search='.$copy['preorder_number'])->json('data');
        $this->assertSame([$copy['id']], array_column($found, 'id'));
        $this->assertCount(3, $this->getJson('/api/v1/preorders?status[]=ordered')->json('data'));
        $this->assertSame(
            [$source['id'], $copy['id'], $split['id']], // nomor berurutan: asal, salinan, hasil split
            array_column($this->getJson('/api/v1/preorders?sort_by=preorder_number&sort_dir=asc')->json('data'), 'id'),
        );

        // ringkasan
        $summary = $this->getJson('/api/v1/preorders/summary')->assertOk()->json();
        $this->assertSame(3, $summary['transaction_count']);
        $ordered = collect($summary['by_status'])->firstWhere('status', 'ordered');
        $this->assertSame(3, $ordered['count']);

        // ekspor: satu baris per pre-order
        $this->assertCount(3, app(PreorderExportImportService::class)->export([]));
        $this->getJson('/api/v1/preorders/export')->assertOk();

        // invoice: salinan dan hasil split sama-sama bisa diterbitkan
        foreach ([$copy['id'], $split['id']] as $id) {
            $this->getJson("/api/v1/preorders/{$id}/invoice")->assertOk()->assertJsonPath('id', $id);
        }
    }

    public function test_duplicating_a_paid_order_adds_no_recognised_revenue_and_no_payment(): void
    {
        $source = $this->order();
        $this->postJson("/api/v1/preorders/{$source['id']}/payments", [
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'down_payment',
        ])->assertCreated();
        $before = $this->getJson('/api/v1/preorders/summary')->json();
        $salesBefore = $this->getJson('/api/v1/reports/sales')->assertOk()->json('totals');
        $paymentsBefore = \App\Models\Payment::count();

        $this->postJson('/api/v1/preorders/duplicate', ['preorder_ids' => [$source['id']]])->assertOk();

        $after = $this->getJson('/api/v1/preorders/summary')->json();
        $this->assertSame($paymentsBefore, \App\Models\Payment::count());
        $this->assertEquals($salesBefore, $this->getJson('/api/v1/reports/sales')->json('totals'));
        // uang yang sudah dibayar tidak berubah: selisih grand_total − outstanding sama
        $this->assertEqualsWithDelta(
            (float) $before['grand_total'] - (float) $before['total_outstanding'],
            (float) $after['grand_total'] - (float) $after['total_outstanding'],
            0.001,
        );
        $this->assertSame(1, \App\Models\Payment::where('preorder_id', $source['id'])->count());
        $this->assertSame(2, Preorder::count());
    }
}
