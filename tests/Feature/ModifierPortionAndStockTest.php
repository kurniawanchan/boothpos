<?php

namespace Tests\Feature;

use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ModifierMaterialFixtures;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 2026-10-31 (permintaan pemilik, lanjutan 087):
 *  - grup "tiap pilihan = 1 porsi" (`portion_per_option`): SOTO + AYAM BAWANG = 2 porsi, harga dasar dua kali; pilihan
 *    pertama tertutup harga dasar, tiap pilihan berikutnya membawa harga dasar di `price_delta` snapshot-nya, jadi
 *    `unit_price = dasar + Σ price_delta` tetap berlaku di semua pembaca;
 *  - POS memuat stok bahan per pilihan di outlet shift (`materials[].on_hand`, `portions`) untuk popup dan kartu.
 */
class ModifierPortionAndStockTest extends TestCase
{
    use ModifierMaterialFixtures, RefreshDatabase, RestaurantFixtures {
        ModifierMaterialFixtures::checkout insteadof RestaurantFixtures;
    }

    private ModifierGroup $rasa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildModifierMaterialWorld();
        $this->rasa = $this->opt['soto']->group;
        $this->rasa->update(['selection_type' => 'multiple', 'min_select' => 1, 'max_select' => 2, 'portion_per_option' => true]);
    }

    public function test_each_option_of_a_portion_group_charges_one_more_base_price_and_deducts_its_material(): void
    {
        $id = $this->checkout([[$this->indomie, 1, $this->opts('soto', 'ayam', 'telur')]])->assertCreated()->json('id');

        $line = OrderItem::where('order_id', $id)->sole();
        // 15.000 × 2 porsi + Telur 3.000
        $this->assertEquals(33000, (float) $line->sell_price);
        $this->assertSame(['0.00', '15000.00', '3000.00'], array_column($line->modifiers, 'price_delta'));
        $this->assertEquals(33000, (float) Order::find($id)->subtotal);
        $this->assertSame(49.0, $this->balance('soto'));
        $this->assertSame(49.0, $this->balance('ayam'));
    }

    public function test_one_option_in_a_portion_group_is_just_the_base_price(): void
    {
        $id = $this->checkout([[$this->indomie, 2, $this->opts('soto')]])->assertCreated()->json('id');

        $this->assertEquals(15000, (float) OrderItem::where('order_id', $id)->value('sell_price'));
    }

    public function test_an_ordinary_group_keeps_the_old_pricing(): void
    {
        $this->rasa->update(['portion_per_option' => false]);
        $id = $this->checkout([[$this->indomie, 1, $this->opts('soto', 'ayam')]])->assertCreated()->json('id');

        $this->assertEquals(15000, (float) OrderItem::where('order_id', $id)->value('sell_price'));
    }

    public function test_changing_a_tab_line_options_keeps_the_original_base_price(): void
    {
        $tab = $this->apiOpenTab($this->session->id, \App\Models\DiningTable::factory()->create()->id)->json('id');
        $line = max($this->apiAddLine($tab, $this->indomie->id, 1, ['modifier_option_ids' => $this->opts('soto', 'ayam')])->assertCreated()->json('lines.*.id'));
        $this->assertEquals(30000, (float) OrderItem::find($line)->sell_price);

        $this->indomie->update(['sell_price' => 99000]);
        $this->patchJson("/api/v1/tabs/{$tab}/items/{$line}", ['modifier_option_ids' => $this->opts('soto')])->assertOk();
        $this->assertEquals(15000, (float) OrderItem::find($line)->sell_price);
    }

    public function test_the_pos_payload_carries_the_flag_and_each_options_material_stock_at_the_shift_outlet(): void
    {
        $this->setStock('soto', $this->outletA, 17);
        $this->setStock('ayam', $this->outletA, 0);
        $this->setStock('cabai', $this->outletA, 5);

        $product = collect($this->getJson('/api/v1/products?pos=1&with_variants=1&with_modifiers=1&per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->indomie->product_id);
        $groups = collect($product['modifier_groups'])->keyBy('name');

        $this->assertTrue($groups['Rasa Kuah']['portion_per_option']);
        $soto = collect($groups['Rasa Kuah']['options'])->firstWhere('name', 'SOTO');
        $this->assertSame(17, $soto['portions']);
        $this->assertSame([['material_id' => $this->mat['soto']->id, 'name' => 'Indomie Soto', 'unit' => 'bungkus', 'quantity' => '1.0000', 'on_hand' => '17.000']], $soto['materials']);
        $this->assertSame(0, collect($groups['Rasa Kuah']['options'])->firstWhere('name', 'AYAM BAWANG')['portions']);
        // Extra Pedas memakai 2 gram per porsi: 5 gram = 2 porsi.
        $this->assertSame(2, collect($groups['Level Pedas']['options'])->firstWhere('name', 'Extra Pedas')['portions']);
        // Pilihan tanpa bahan: tak terbatas (null).
        $this->assertNull(collect($groups['Level Pedas']['options'])->firstWhere('name', 'Sedang')['portions']);

        $lookup = collect($this->getJson('/api/v1/variants/lookup?pos=1&with_modifiers=1&q='.urlencode('Indomie'))->assertOk()->json('data'))
            ->firstWhere('variant_id', $this->indomie->id);
        $this->assertSame(17, collect(collect($lookup['modifier_groups'])->firstWhere('name', 'Rasa Kuah')['options'])->firstWhere('name', 'SOTO')['portions']);
    }

    public function test_the_modifier_group_api_saves_and_returns_the_portion_flag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $res = $this->postJson('/api/v1/modifier-groups', [
            'name' => 'Rasa Goreng', 'selection_type' => 'multiple', 'is_required' => true, 'min_select' => 1, 'max_select' => 2,
            'portion_per_option' => true, 'options' => [['name' => 'Original', 'price_delta' => 0]],
        ])->assertCreated();
        $res->assertJsonPath('portion_per_option', true);

        $this->putJson('/api/v1/modifier-groups/'.$res->json('id'), ['name' => 'Rasa Goreng', 'portion_per_option' => false])->assertOk()
            ->assertJsonPath('portion_per_option', false);
    }
}
