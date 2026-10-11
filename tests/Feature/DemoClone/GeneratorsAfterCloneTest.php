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
        Storage::fake('public');
        Storage::fake('local');
        $this->buildGraph('live');
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
        foreach ([ModeGate::LIVE, ModeGate::DEMO] as $mode) {
            ModeGate::runAs($mode, function () {
                $order = $this->invokePrivate(app(OrderService::class), 'generateOrderNumber');
                $preorder = app(PreorderService::class)->generateNumber();
                $po = $this->invokePrivate(app(PurchaseOrderService::class), 'generatePoNumber');

                $this->assertFalse(DB::table('orders')->where('order_number', $order)->exists());
                $this->assertFalse(DB::table('preorders')->where('preorder_number', $preorder)->exists());
                $this->assertFalse(DB::table('purchase_orders')->where('po_number', $po)->exists());
            });
        }
    }

    public function test_a_new_product_prefix_can_still_be_built_for_the_demo_artist_and_category(): void
    {
        $artist = DB::table('artists')->where('data_mode', 'demo')->value('code');
        $category = DB::table('categories')->where('data_mode', 'demo')->whereNotNull('parent_id')->value('code');

        $prefix = ModeGate::runAs(ModeGate::DEMO, fn () => app(ProductCodeGenerator::class)->buildCodePrefix($artist, $category, 'NEW'));

        $this->assertSame("{$artist}-{$category}-NEW", $prefix);
    }
}
