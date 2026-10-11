<?php

namespace Tests\Feature;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 US3 — modifier pada baris tab: harga efektif dihitung server, snapshot yang
 * tidak berubah oleh edit menu, aturan penggabungan, catatan, struk (FR-024–FR-027).
 */
class TabModifiersTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private int $tab;
    private ProductVariant $coffee;
    private ModifierGroup $size;
    private ModifierGroup $addons;
    /** @var array<int, ModifierOption> */
    private array $sizeOpts;
    /** @var array<int, ModifierOption> */
    private array $addonOpts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->tab = $this->apiOpenTab($this->makeShift($this->cashier)->id)->json('id');

        $this->coffee = $this->makeVariant(stock: 50, price: 25000);

        $this->size = ModifierGroup::factory()->create(['name' => 'Size', 'selection_type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
        $this->sizeOpts = [
            $this->size->options()->create(['name' => 'Regular', 'price_delta' => 0]),
            $this->size->options()->create(['name' => 'Large', 'price_delta' => 5000]),
        ];
        $this->addons = ModifierGroup::factory()->create(['name' => 'Add-ons', 'selection_type' => 'multiple', 'is_required' => false, 'min_select' => 0, 'max_select' => 2]);
        $this->addonOpts = [
            $this->addons->options()->create(['name' => 'Extra shot', 'price_delta' => 8000]),
            $this->addons->options()->create(['name' => 'Oat milk', 'price_delta' => 10000]),
        ];
        $this->size->products()->attach($this->coffee->product_id);
        $this->addons->products()->attach($this->coffee->product_id);
    }

    private function add(array $optionIds, array $extra = [], int $qty = 1)
    {
        return $this->apiAddLine($this->tab, $this->coffee->id, $qty, array_merge(['modifier_option_ids' => $optionIds], $extra));
    }

    public function test_the_line_price_is_the_base_price_plus_the_chosen_differences(): void
    {
        // 25.000 + Large 5.000 + Extra shot 8.000 = 38.000 per unit, x2
        $this->add([$this->sizeOpts[1]->id, $this->addonOpts[0]->id], ['note' => 'tanpa es'], 2)
            ->assertCreated()
            ->assertJsonPath('lines.0.unit_price', '38000.00')
            ->assertJsonPath('lines.0.line_total', '76000.00')
            ->assertJsonPath('total_amount', '76000.00')
            ->assertJsonPath('lines.0.note', 'tanpa es')
            ->assertJsonPath('lines.0.modifiers.0.group', 'Size')
            ->assertJsonPath('lines.0.modifiers.0.option', 'Large')
            ->assertJsonPath('lines.0.modifiers.0.price_delta', '5000.00')
            ->assertJsonPath('lines.0.modifiers.1.option', 'Extra shot');

        $item = OrderItem::first();
        $this->assertEquals(38000, $item->sell_price);       // harga efektif → rumus laporan tak berubah
        $this->assertEquals(12500, $item->cost_price);       // modifier tidak mengubah biaya
        $this->assertEquals(76000, $item->line_total);
    }

    public function test_a_violated_rule_is_a_422_that_names_the_group_and_nothing_is_added(): void
    {
        $res = $this->add([])->assertStatus(422)->assertJsonValidationErrors(['modifier_option_ids']);

        $this->assertStringContainsString('Size', $res->json('errors.modifier_option_ids.0'));
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(50, $this->coffee->fresh()->current_stock);
    }

    public function test_too_many_choices_in_a_group_is_rejected(): void
    {
        $extra = $this->addons->options()->create(['name' => 'Syrup', 'price_delta' => 3000]);

        $this->add([$this->sizeOpts[0]->id, $this->addonOpts[0]->id, $this->addonOpts[1]->id, $extra->id])
            ->assertStatus(422)->assertJsonValidationErrors(['modifier_option_ids']);
    }

    public function test_an_option_from_another_products_group_is_rejected(): void
    {
        $other = ModifierGroup::factory()->create(['name' => 'Foreign', 'selection_type' => 'single', 'is_required' => false, 'min_select' => 0, 'max_select' => 1]);
        $foreign = $other->options()->create(['name' => 'X', 'price_delta' => 100]);

        $this->add([$this->sizeOpts[0]->id, $foreign->id])->assertStatus(422)->assertJsonValidationErrors(['modifier_option_ids']);
    }

    public function test_a_product_without_modifiers_still_adds_normally_and_ignores_an_empty_list(): void
    {
        $plain = $this->makeVariant(stock: 5, price: 10000);

        $this->apiAddLine($this->tab, $plain->id, 1, ['modifier_option_ids' => []])->assertCreated()->assertJsonPath('lines.0.modifiers', []);
    }

    public function test_the_snapshot_survives_renaming_repricing_and_deactivating_options_and_groups(): void
    {
        $this->add([$this->sizeOpts[1]->id, $this->addonOpts[0]->id])->assertCreated();

        $this->sizeOpts[1]->update(['name' => 'Besar', 'price_delta' => 99999]);
        $this->addonOpts[0]->update(['is_active' => false]);
        $this->size->update(['name' => 'Ukuran', 'is_active' => false]);

        $line = $this->getJson("/api/v1/tabs/{$this->tab}")->assertOk()->json('lines.0');
        $this->assertSame('38000.00', $line['unit_price']);
        $this->assertSame('Size', $line['modifiers'][0]['group']);
        $this->assertSame('Large', $line['modifiers'][0]['option']);
        $this->assertSame('5000.00', $line['modifiers'][0]['price_delta']);
        $this->assertSame('Extra shot', $line['modifiers'][1]['option']);
    }

    public function test_identical_unsent_choices_merge_while_different_ones_stay_separate(): void
    {
        $this->add([$this->sizeOpts[1]->id], [], 1)->assertCreated();
        $same = $this->add([$this->sizeOpts[1]->id], [], 2)->assertCreated();
        $same->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.qty', 3);

        $other = $this->add([$this->sizeOpts[0]->id], [], 1)->assertCreated();
        $other->assertJsonCount(2, 'lines');

        $withAddon = $this->add([$this->sizeOpts[1]->id, $this->addonOpts[0]->id], [], 1)->assertCreated();
        $withAddon->assertJsonCount(3, 'lines');
    }

    public function test_the_order_in_which_options_are_sent_does_not_matter_for_merging(): void
    {
        $this->add([$this->sizeOpts[1]->id, $this->addonOpts[0]->id, $this->addonOpts[1]->id])->assertCreated();

        $this->add([$this->addonOpts[1]->id, $this->addonOpts[0]->id, $this->sizeOpts[1]->id])
            ->assertCreated()->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.qty', 2);
    }

    public function test_changing_the_choices_of_an_unsent_line_reprices_it_from_its_original_base(): void
    {
        $line = $this->add([$this->sizeOpts[0]->id])->json('lines.0.id'); // 25.000
        // Harga menu naik SETELAH baris ditambahkan: baris tetap memakai harga lamanya sebagai dasar.
        $this->coffee->update(['sell_price' => 30000]);

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['modifier_option_ids' => [$this->sizeOpts[1]->id]])
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', '30000.00') // 25.000 (dasar lama) + Large 5.000
            ->assertJsonPath('lines.0.modifiers.0.option', 'Large');
    }

    public function test_changing_only_the_quantity_keeps_the_modifiers_and_price(): void
    {
        $line = $this->add([$this->sizeOpts[1]->id, $this->addonOpts[0]->id])->json('lines.0.id');

        $this->patchJson("/api/v1/tabs/{$this->tab}/items/{$line}", ['qty' => 3])
            ->assertOk()->assertJsonPath('lines.0.unit_price', '38000.00')->assertJsonPath('lines.0.line_total', '114000.00')
            ->assertJsonCount(2, 'lines.0.modifiers');
    }

    public function test_the_completed_sale_shows_options_and_note_on_the_receipt_and_the_order_detail(): void
    {
        $this->add([$this->sizeOpts[1]->id], ['note' => 'tanpa es'])->assertCreated();
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 30000]])->assertOk()->assertJsonPath('status', 'completed');

        $receipt = $this->getJson("/api/v1/orders/{$this->tab}/receipt")->assertOk();
        $receipt->assertJsonPath('items.0.price', '30000.00')
            ->assertJsonPath('items.0.note', 'tanpa es')
            ->assertJsonPath('items.0.modifiers.0.option', 'Large');

        $detail = $this->getJson("/api/v1/orders/{$this->tab}")->assertOk();
        $detail->assertJsonPath('items.0.note', 'tanpa es')->assertJsonPath('items.0.modifiers.0.option', 'Large');
    }

    public function test_a_retail_sale_line_has_no_modifiers_in_its_receipt(): void
    {
        $plain = $this->makeVariant(stock: 5, price: 10000);
        $sale = $this->postJson('/api/v1/orders', [
            'session_id' => $this->makeShift($this->cashier)->id, 'local_ref' => $this->newLocalRef(),
            'items' => [['variant_id' => $plain->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertCreated()->json('id');

        $item = $this->getJson("/api/v1/orders/{$sale}/receipt")->assertOk()->json('items.0');
        $this->assertSame([], $item['modifiers']);
        $this->assertNull($item['note']);
    }
}
