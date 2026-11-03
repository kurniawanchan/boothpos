<?php

namespace Tests\Feature;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\PaymentChannel;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutletFixtures;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US1 — konteks dan menu publik: hanya menu outlet QR, hanya field daftar-putih (research D8).
 */
class SelfServiceContextMenuTest extends TestCase
{
    use OutletFixtures;
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
    }

    public function test_context_carries_outlet_table_tax_and_the_qris_channel(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'tax_label'], ['value' => 'PB1', 'type' => 'string']);

        $this->getJson($this->ssUrl('/context'))->assertOk()
            ->assertJsonPath('data.table.name', 'Meja 5')
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.tax.label', 'PB1')
            ->assertJsonPath('data.tax.rate', 10)
            ->assertJsonPath('data.tax.show', true)
            ->assertJsonPath('data.payment.method', 'qris')
            ->assertJsonPath('data.payment.channel_id', $this->ssChannel->id)
            ->assertJsonStructure(['data' => ['outlet' => ['name'], 'payment' => ['qr_image_url']]]);
    }

    public function test_without_an_active_qris_channel_with_an_image_ordering_is_unavailable(): void
    {
        PaymentChannel::query()->update(['is_active' => false]);

        $this->getJson($this->ssUrl('/context'))->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.unavailable_reason', 'qris_unavailable');

        PaymentChannel::query()->update(['is_active' => true, 'qr_image_path' => null]);
        $this->getJson($this->ssUrl('/context'))->assertOk()->assertJsonPath('data.available', false);

        // Rekening bank biasa bukan QRIS.
        PaymentChannel::query()->update(['qr_image_path' => 'x.png', 'type' => 'bank_transfer']);
        $this->getJson($this->ssUrl('/context'))->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_the_menu_lists_only_what_this_outlet_carries_and_flags_sold_out_items(): void
    {
        $here = $this->makeMenuItem(price: 20000);
        $soldOut = $this->makeMenuItem(price: 15000);
        $elsewhere = $this->makeMenuItem(price: 10000);
        $others = $this->makeOutlets(2);
        $this->carry($elsewhere->product, [$others[1]]); // hanya dibawa outlet lain
        $this->ssMarkSoldOut($soldOut->product);

        $body = $this->getJson($this->ssUrl('/menu'))->assertOk()->json('data');
        $products = collect($body['products'])->keyBy('id');

        $this->assertTrue($products->has($here->product_id));
        $this->assertTrue($products->has($soldOut->product_id));
        $this->assertFalse($products->has($elsewhere->product_id), 'item outlet lain tidak boleh tampil');
        $this->assertFalse($products[$here->product_id]['sold_out']);
        $this->assertTrue($products[$soldOut->product_id]['sold_out']);
        $this->assertSame('20000.00', $products[$here->product_id]['variants'][0]['price']);
    }

    public function test_the_menu_never_exposes_cost_stock_or_internal_ids(): void
    {
        $variant = $this->makeMenuItem(stock: 37, price: 20000, variantOverrides: ['cost_price' => 7777]);
        $group = ModifierGroup::factory()->create(['name' => 'Level Pedas']);
        ModifierOption::factory()->create(['modifier_group_id' => $group->id, 'name' => 'Pedas', 'price_delta' => 2000]);
        $variant->product->modifierGroups()->attach($group->id);

        $raw = $this->getJson($this->ssUrl('/menu'))->assertOk()->getContent();

        foreach (['cost_price', 'current_stock', 'on_hand', 'home_outlet', 'outlet_id', 'sku', 'low_stock', 'materials', '7777', 'code_prefix'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "menu publik tidak boleh memuat {$forbidden}");
        }

        $products = collect($this->getJson($this->ssUrl('/menu'))->json('data.products'))->keyBy('id');
        $groups = $products[$variant->product_id]['modifier_groups'];
        $this->assertSame('Level Pedas', $groups[0]['name']);
        $this->assertSame('Pedas', $groups[0]['options'][0]['name']);
        $this->assertSame('2000.00', $groups[0]['options'][0]['price_delta']);
    }

    public function test_search_and_category_filters_work(): void
    {
        $a = $this->makeMenuItem(price: 10000);
        $b = $this->makeMenuItem(price: 12000);
        $a->product->update(['name' => 'Kopi Susu']);
        $b->product->update(['name' => 'Nasi Goreng']);

        $names = fn ($res) => collect($res->json('data.products'))->pluck('name')->all();

        $this->assertSame(['Kopi Susu'], $names($this->getJson($this->ssUrl('/menu?q=kopi'))->assertOk()));
        $this->assertSame(['Nasi Goreng'], $names($this->getJson($this->ssUrl('/menu?category_id='.$b->product->category_id))->assertOk()));
    }

    public function test_menu_response_has_categories_only_with_orderable_products(): void
    {
        $item = $this->makeMenuItem();

        $res = $this->getJson($this->ssUrl('/menu'))->assertOk();

        $this->assertContains($item->product->category_id, collect($res->json('data.categories'))->pluck('id')->all());
    }
}
