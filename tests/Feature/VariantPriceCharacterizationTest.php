<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T003) — karakterisasi perilaku harga SEBELUM semua penulisan `sell_price`
 * dilewatkan lewat PriceService: edit varian berlaku seketika dan meninggalkan baris audit `price_changed`;
 * kolom harga di impor produk menulis harga; varian BOM-selesai mengunci `cost_price`.
 */
class VariantPriceCharacterizationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function updatePayload($variant, array $over = []): array
    {
        return array_merge([
            'variant_name' => $variant->variant_name, 'sell_price' => (float) $variant->sell_price,
            'cost_price' => (float) $variant->cost_price, 'is_active' => true,
        ], $over);
    }

    public function test_a_variant_price_edit_applies_immediately_and_is_audited(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);

        $this->putJson("/api/v1/variants/{$variant->id}", $this->updatePayload($variant, ['sell_price' => 12500]))->assertOk();

        $this->assertSame('12500.00', (string) $variant->fresh()->sell_price);
        $this->assertTrue(ActivityLog::where('action', 'price_changed')->where('entity_id', $variant->id)->exists());
    }

    public function test_an_unchanged_price_does_not_write_a_price_changed_row_per_edit_of_other_fields(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);

        $this->putJson("/api/v1/variants/{$variant->id}", $this->updatePayload($variant, ['variant_name' => 'Besar']))->assertOk();

        $this->assertSame('10000.00', (string) $variant->fresh()->sell_price);
        $this->assertSame('Besar', $variant->fresh()->variant_name);
    }

    public function test_a_bom_complete_variant_refuses_a_different_cost_price(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $variant->forceFill(['bom_complete' => true, 'cost_price' => 4000])->save();

        $this->putJson("/api/v1/variants/{$variant->id}", $this->updatePayload($variant, ['cost_price' => 1]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'cost_price_locked_by_bom');
    }
}
