<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\CloneRegistry;
use App\Services\DemoClone\LiveToDemoCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

class LiveToDemoClonerTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    private array $live;

    private array $oldDemo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->live = $this->buildGraph('live');
        $this->oldDemo = $this->buildGraph('demo');
    }

    private function mode(string $table, string $mode)
    {
        return DB::table($table)->whereRaw(CloneRegistry::scope($table, $mode));
    }

    private function demo(string $table): object
    {
        return $this->mode($table, 'demo')->orderBy('id')->first();
    }

    public function test_plan_reports_counts_and_files_without_writing(): void
    {
        $plan = (new LiveToDemoCloner)->plan();

        $this->assertSame(['demo_delete' => 1, 'live_clone' => 1], $plan['tables']['orders']);
        $this->assertSame(['demo_delete' => 2, 'live_clone' => 2], $plan['tables']['preorders']);
        $this->assertSame(4, $plan['files']['files']); // 3 gambar + 1 bukti bayar LIVE
        $this->assertGreaterThan(0, $plan['files']['bytes']);
        $this->assertSame(1, $this->mode('orders', 'demo')->count());
        $this->assertSame($this->oldDemo['order'], $this->demo('orders')->id);
    }

    /**
     * Spec 042 (T014): produk LIVE bersegmen 1 huruf tidak boleh menggagalkan
     * seluruh klon. Fixture memakai segmen 3 huruf; di sini segmen LIVE diubah
     * menjadi "A" (kode dan SKU ikut diubah) sebelum klon dijalankan.
     */
    public function test_a_live_product_with_a_one_letter_segment_clones_and_keeps_its_length(): void
    {
        DB::table('products')->where('data_mode', 'live')->update([
            'code_prefix' => 'LIV-LC-A',
            'product_segment' => 'A',
        ]);
        DB::table('product_variants')->where('data_mode', 'live')->get()->each(function ($variant) {
            DB::table('product_variants')->where('id', $variant->id)->update([
                'sku' => str_replace('LIV-LC-ABC-', 'LIV-LC-A-', $variant->sku),
            ]);
        });

        (new LiveToDemoCloner)->run();

        $demo = DB::table('products')->where('data_mode', 'demo')->first();
        $this->assertNotNull($demo);
        $this->assertSame('A', $demo->product_segment);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{2}-A$/', $demo->code_prefix);

        $skus = DB::table('product_variants')->where('data_mode', 'demo')->pluck('sku');
        $this->assertCount(2, $skus);
        foreach ($skus as $sku) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{2}-A-00\d$/', $sku);
        }
    }

    public function test_old_demo_rows_are_replaced_and_every_table_mirrors_live(): void
    {
        $report = (new LiveToDemoCloner)->run();

        $this->assertFalse(DB::table('artists')->where('code', 'DMO')->exists());
        $this->assertFalse(DB::table('orders')->where('id', $this->oldDemo['order'])->exists());

        foreach (array_keys(CloneRegistry::tables()) as $table) {
            $this->assertSame(
                $this->mode($table, 'live')->count(),
                $this->mode($table, 'demo')->count(),
                "{$table}: jumlah DEMO harus sama dengan LIVE",
            );
        }

        $this->assertSame(1, $report['cloned']['orders']);
        $this->assertSame(1, $report['deleted']['orders']);
    }

    public function test_foreign_keys_point_at_demo_rows_and_self_references_are_patched(): void
    {
        (new LiveToDemoCloner)->run();

        $order = $this->demo('orders');
        $this->assertSame('demo', DB::table('customers')->where('id', $order->customer_id)->value('data_mode'));
        $this->assertSame('demo', DB::table('cashier_sessions')->where('id', $order->session_id)->value('data_mode'));
        $this->assertSame('demo', DB::table('events')->where('id', $order->event_id)->value('data_mode'));
        // user dibagi: tetap user yang sama
        $this->assertSame($this->live['user'], (int) $order->user_id);

        $child = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->first();
        $this->assertSame('demo', DB::table('categories')->where('id', $child->parent_id)->value('data_mode'));

        $split = DB::table('preorders')->where('data_mode', 'demo')->whereNotNull('source_preorder_id')->first();
        $this->assertSame('demo', DB::table('preorders')->where('id', $split->source_preorder_id)->value('data_mode'));

        $bom = $this->demo('product_variant_bom_lines');
        $this->assertSame('demo', DB::table('product_variants')->where('id', $bom->product_variant_id)->value('data_mode'));
        $poItem = DB::table('purchase_order_items')->where('id', $bom->purchase_order_item_id)->first();
        $this->assertSame('demo', DB::table('purchase_orders')->where('id', $poItem->purchase_order_id)->value('data_mode'));
    }

    public function test_unique_values_are_rewritten_by_their_rule(): void
    {
        (new LiveToDemoCloner)->run();

        $this->assertSame('ZLI', $this->demo('artists')->code);
        $this->assertSame('ZL', DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->value('code'));
        $this->assertSame('VND-LIVE-DM', $this->demo('vendors')->code);
        $this->assertSame('MAT-LIVE-DM', $this->demo('materials')->code);
        $this->assertSame('TRX-LIVE-0001-DM', $this->demo('orders')->order_number);
        $this->assertSame('PO-LIVE-0001-DM', $this->demo('purchase_orders')->po_number);
        $this->assertEqualsCanonicalizing(
            ['PRE-LIVE-0001-DM', 'PRE-LIVE-0002-DM'],
            $this->mode('preorders', 'demo')->pluck('preorder_number')->all(),
        );
        $this->assertSame('ZLI-ZL-ABC', $this->demo('products')->code_prefix);
        $this->assertEqualsCanonicalizing(
            ['ZLI-ZL-ABC-001', 'ZLI-ZL-ABC-002'],
            $this->mode('product_variants', 'demo')->pluck('sku')->all(),
        );
        $this->assertNotSame(
            DB::table('orders')->where('data_mode', 'live')->value('local_ref'),
            $this->demo('orders')->local_ref,
        );
        $this->assertSame(36, strlen($this->demo('orders')->local_ref));
        $this->assertSame(3, $this->mode('payments', 'demo')->whereNotNull('client_ref')->distinct()->count('client_ref'));
        $this->assertNotSame(
            DB::table('payment_proofs')->where('data_mode', 'live')->value('proof_token'),
            $this->demo('payment_proofs')->proof_token,
        );
    }

    public function test_stock_movement_references_are_remapped_by_type(): void
    {
        (new LiveToDemoCloner)->run();

        $order = $this->demo('orders');
        $orderItem = $this->demo('order_items');
        $preorderItem = $this->demo('preorder_items');

        $sale = $this->mode('stock_movements', 'demo')->where('type', 'sale')->first();
        $return = $this->mode('stock_movements', 'demo')->where('type', 'return')->first();
        $purchase = $this->mode('stock_movements', 'demo')->where('type', 'purchase')->first();
        $adjust = $this->mode('stock_movements', 'demo')->where('type', 'adjustment')->first();

        $this->assertSame((int) $order->id, (int) $sale->reference_id);
        $this->assertSame((int) $orderItem->id, (int) $return->reference_id);
        $this->assertSame((int) $preorderItem->id, (int) $purchase->reference_id);
        $this->assertNull($adjust->reference_id);
        $this->assertSame('MasterDataImport', $adjust->reference_type);

        $poItem = DB::table('purchase_order_items')
            ->whereIn('purchase_order_id', $this->mode('purchase_orders', 'demo')->pluck('id'))->first();
        $this->assertSame((int) $poItem->id, (int) $this->demo('material_stock_movements')->reference_id);
    }

    public function test_unknown_non_null_references_are_dropped_and_counted_never_copied(): void
    {
        $this->row('stock_movements', [
            'data_mode' => 'live', 'variant_id' => $this->live['variant1'], 'type' => 'adjustment',
            'qty_change' => 0, 'stock_before' => 0, 'stock_after' => 0,
            'reference_type' => 'Mystery', 'reference_id' => $this->live['order'],
        ]);

        $report = (new LiveToDemoCloner)->run();

        $this->assertSame(1, $report['references_dropped']);
        $this->assertSame(0, $this->mode('stock_movements', 'demo')->where('reference_id', $this->live['order'])->count());
    }

    public function test_values_timestamps_and_pii_are_copied_verbatim(): void
    {
        $liveOrder = DB::table('orders')->where('id', $this->live['order'])->first();
        (new LiveToDemoCloner)->run();

        $this->assertSame($liveOrder->created_at, $this->demo('orders')->created_at);
        $customer = $this->demo('customers');
        $this->assertSame('live.customer@example.test', $customer->email);
        $this->assertSame('+62-000-LIVE-0001', $customer->phone);
        $this->assertSame('demo', $customer->data_mode);
    }

    public function test_soft_deleted_live_rows_are_cloned_as_soft_deleted(): void
    {
        $stamp = now()->subDay()->format('Y-m-d H:i:s');
        $deletedVendor = $this->row('vendors', ['data_mode' => 'live', 'code' => 'GONE', 'name' => 'Deleted vendor', 'deleted_at' => $stamp]);

        (new LiveToDemoCloner)->run();

        $clone = DB::table('vendors')->where('data_mode', 'demo')->where('code', 'GONE-DM')->first();
        $this->assertNotNull($clone);
        $this->assertSame($stamp, $clone->deleted_at);
        $this->assertNotSame($deletedVendor, $clone->id);
    }

    public function test_live_rows_are_untouched_by_the_core_run(): void
    {
        $before = $this->mode('orders', 'live')->first();

        (new LiveToDemoCloner)->run();

        $this->assertEquals($before, $this->mode('orders', 'live')->first());
        $this->assertSame(1, $this->mode('artists', 'live')->count());
        $this->assertSame('LIV', $this->mode('artists', 'live')->value('code'));
    }
}
