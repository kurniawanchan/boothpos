<?php

namespace Tests\Feature\DemoClone;

use App\Services\DemoClone\LiveToDemoCloner;
use App\Services\OrderService;
use App\Services\PreorderService;
use App\Services\ProductCodeGenerator;
use App\Services\PurchaseOrderService;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * Pembangkit nomor menghitung baris HARI INI lintas kedua mode (+1). Salinan
 * menggandakan hitungan itu, jadi nomor berikutnya hanya melompat — tidak
 * boleh bertabrakan dengan nomor yang sudah ada, di mode mana pun.
 */
class GeneratorsAfterCloneTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan jam SEBELUM graf dibangun: created_at fixture, $ymd, dan hitungan
        // "hari ini" harus memakai hari yang sama walau test berjalan lewat tengah
        // malam. Carbon test-now direset otomatis oleh tearDown TestCase.
        $this->travelTo(now()->setTime(12, 0));
        Storage::fake('public');
        Storage::fake('local');
        $this->buildGraph('live');

        // Nomor fixture (TRX-LIVE-0001 dst.) tidak berbentuk keluaran pembangkit
        // (TRX-YYYYMMDD-NNNN), jadi uji tabrakan akan lulus kosong. Ubah baris
        // LIVE ke bentuk pembangkit untuk hari ini SEBELUM salin, supaya salinan
        // DEMO ('-DM') dan hitungan lintas mode benar-benar diuji.
        $ymd = now()->format('Ymd');
        DB::table('orders')->where('data_mode', 'live')->update(['order_number' => "TRX-{$ymd}-0001"]);
        DB::table('purchase_orders')->where('data_mode', 'live')->update(['po_number' => "PO-{$ymd}-0001"]);
        $preorderIds = DB::table('preorders')->where('data_mode', 'live')->orderBy('id')->pluck('id');
        foreach ($preorderIds as $i => $id) {
            DB::table('preorders')->where('id', $id)->update(['preorder_number' => sprintf('PO-%s-%04d', $ymd, $i + 1)]);
        }

        (new LiveToDemoCloner)->run();
    }

    private function invokePrivate(object $object, string $method): string
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    public function test_new_order_preorder_and_po_numbers_do_not_collide_in_either_mode(): void
    {
        $today = now()->toDateString();
        $ymd = now()->format('Ymd');
        $count = fn (string $table) => DB::table($table)->whereDate('created_at', $today)->count();

        // Prasyarat uji: baris hari ini ada di kedua mode, jadi hitungan memang berlipat.
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'demo')->count());
        $this->assertSame(2, DB::table('preorders')->where('data_mode', 'live')->count());
        $this->assertSame(2, DB::table('preorders')->where('data_mode', 'demo')->count());
        $this->assertSame(1, DB::table('purchase_orders')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('purchase_orders')->where('data_mode', 'demo')->count());

        foreach ([ModeGate::LIVE, ModeGate::DEMO] as $mode) {
            ModeGate::runAs($mode, function () use ($count, $ymd) {
                $orderCount = $count('orders');
                $preorderCount = $count('preorders');
                $poCount = $count('purchase_orders');

                $order = $this->invokePrivate(app(OrderService::class), 'generateOrderNumber');
                $preorder = app(PreorderService::class)->generateNumber();
                $po = $this->invokePrivate(app(PurchaseOrderService::class), 'generatePoNumber');

                // (a) tidak ada di tabel mana pun, di mode mana pun
                $this->assertFalse(DB::table('orders')->where('order_number', $order)->exists());
                $this->assertFalse(DB::table('preorders')->where('preorder_number', $preorder)->exists());
                $this->assertFalse(DB::table('purchase_orders')->where('po_number', $po)->exists());

                // (b) hitungan hari ini lintas kedua mode (+1): 1 live + 1 salinan demo -> ...-0003
                $this->assertSame(sprintf('TRX-%s-%04d', $ymd, $orderCount + 1), $order);
                $this->assertSame(sprintf('PO-%s-%04d', $ymd, $preorderCount + 1), $preorder);
                $this->assertSame(sprintf('PO-%s-%04d', $ymd, $poCount + 1), $po);
            });
        }

        $this->assertSame("TRX-{$ymd}-0003", $this->invokePrivate(app(OrderService::class), 'generateOrderNumber'));
    }

    public function test_a_new_product_prefix_can_still_be_built_for_the_demo_artist_and_category(): void
    {
        $artist = DB::table('artists')->where('data_mode', 'demo')->value('code');
        $category = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->value('code');
        $generator = app(ProductCodeGenerator::class);

        $prefix = ModeGate::runAs(ModeGate::DEMO, fn () => $generator->buildCodePrefix($artist, $category, 'NEW'));

        $this->assertSame("{$artist}-{$category}-NEW", $prefix);

        // Segmen produk hasil salinan (fixture LIVE memakai 'ABC') sudah terpakai di DEMO.
        $this->assertTrue(DB::table('products')->where('data_mode', 'demo')->where('code_prefix', "{$artist}-{$category}-ABC")->exists());
        $this->expectException(ValidationException::class);
        ModeGate::runAs(ModeGate::DEMO, fn () => $generator->buildCodePrefix($artist, $category, 'ABC'));
    }
}
