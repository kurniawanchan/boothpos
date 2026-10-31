<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 087 US4 (T042) — penjualan tidak pernah ditolak karena bahan; saldo minus diberi peringatan dan bisa disaring.
 */
class MaterialNegativeStockTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->setStock('telur', $this->outletA, 1);
    }

    public function test_a_checkout_short_of_a_material_succeeds_with_a_warning(): void
    {
        $r = $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated();

        $this->assertSame(-1.0, $this->balance('telur'));
        $r->assertJsonPath('material_warnings.0', [
            'material_id' => $this->mat['telur']->id, 'material' => 'Telur', 'unit' => 'butir', 'outlet_id' => $this->outletA->id,
            'outlet' => $this->outletA->name, 'stock_before' => '1.000', 'required' => '2.000', 'stock_after' => '-1.000',
        ]);
        $this->assertCount(1, $r->json('material_warnings'));
        $r->assertJsonPath('id', fn ($id) => is_int($id)); // bentuk OrderResource tetap utuh
    }

    public function test_settling_a_tab_short_of_a_material_succeeds_with_a_warning(): void
    {
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto', 'double')])->assertCreated();

        $r = $this->apiPay($tab, [['method' => 'cash', 'amount' => (float) Order::find($tab)->total_amount]])->assertOk();

        $r->assertJsonPath('status', 'completed')->assertJsonPath('material_warnings.0.stock_after', '-1.000');
    }

    public function test_without_a_shortage_there_is_no_warnings_key_at_all(): void
    {
        $r = $this->checkout([[$this->indomie, 1, $this->opts('ayam')]])->assertCreated();

        $this->assertArrayNotHasKey('material_warnings', $r->json());
    }

    public function test_the_materials_list_filters_negative_balances_of_visible_outlets_only(): void
    {
        $this->checkout([[$this->indomie, 2, $this->opts('soto', 'telur')]])->assertCreated();
        $this->setStock('cabai', $this->outletB, 0);
        app(\App\Services\MaterialStockService::class)->applyMovement($this->mat['cabai'], $this->outletB->id, 'sale', -5, 'order_item', 999999);

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $all = collect($this->getJson('/api/v1/materials?stock_status=negative')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Cabai Bubuk', 'Telur'], $all);

        $narrowed = collect($this->withHeader('X-Outlet-Id', (string) $this->outletA->id)->getJson('/api/v1/materials?stock_status=negative')->assertOk()->json('data'));
        $this->assertSame(['Telur'], $narrowed->pluck('name')->all());
        $this->assertSame('-1.000', collect($narrowed->first()['outlet_stock'])->firstWhere('outlet_id', $this->outletA->id)['on_hand']);

        $this->getJson('/api/v1/materials?stock_status=bogus')->assertStatus(422);
    }

    /** 2026-10-31 (permintaan pemilik "filter lengkap") — stok tersedia / habis per outlet yang terlihat. */
    public function test_the_materials_list_filters_available_and_out_of_stock_per_visible_outlet(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->setStock('soto', $this->outletA, 0);
        $this->setStock('soto', $this->outletB, 7);
        $this->setStock('telur', $this->outletA, 3);

        $names = fn (string $status, $outlet) => collect($this->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson("/api/v1/materials?stock_status={$status}&per_page=100")->assertOk()->json('data'))->pluck('name');

        $this->assertContains('Telur', $names('available', $this->outletA));
        $this->assertNotContains('Indomie Soto', $names('available', $this->outletA));
        $this->assertContains('Indomie Soto', $names('out', $this->outletA));
        $this->assertContains('Indomie Soto', $names('available', $this->outletB));
        $this->assertNotContains('Telur', $names('out', $this->outletA));
    }
}
