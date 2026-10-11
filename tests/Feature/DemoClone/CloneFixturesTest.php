<?php

namespace Tests\Feature\DemoClone;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCloneFixtures;
use Tests\TestCase;

/**
 * Memastikan fixture itu sendiri valid terhadap skema MySQL sungguhan
 * (CHECK constraint payments, kolom NOT NULL, FK) — kalau ini gagal,
 * test fitur salin tidak bermakna.
 */
class CloneFixturesTest extends TestCase
{
    use BuildsCloneFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_live_and_demo_graphs_can_be_built_side_by_side(): void
    {
        $live = $this->buildGraph('live');
        $demo = $this->buildGraph('demo');

        $this->assertNotSame($live['order'], $demo['order']);
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('orders')->where('data_mode', 'demo')->count());
        $this->assertSame(2, DB::table('preorders')->where('data_mode', 'live')->count());
        $this->assertSame(2, DB::table('categories')->where('data_mode', 'live')->count());
        $this->assertSame(3, DB::table('payments')->where('data_mode', 'live')->count());
        $this->assertSame(1, DB::table('purchase_order_items')->where('purchase_order_id', $live['po'])->count());
        Storage::disk('public')->assertExists('products/live-product.jpg');
        Storage::disk('local')->assertExists('payment-proofs/demo-proof.jpg');
    }
}
