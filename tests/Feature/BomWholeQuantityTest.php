<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\ProductVariantBomLine;
use App\Models\User;
use App\Rules\WholeBomQuantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Support\BuildsBomFixtures;
use Tests\TestCase;

/**
 * 036-bom-variant-stock-ux (US1, FR-001/FR-006) — jumlah BOM per unit produk
 * adalah bilangan bulat >= 1 di SEMUA pintu masuk (satu aturan,
 * WholeBomQuantity), tanpa menulis ulang data lama yang pecahan.
 */
class BomWholeQuantityTest extends TestCase
{
    use BuildsBomFixtures, RefreshDatabase;

    private User $owner;

    private Artist $seller;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->owner, 'sanctum');
        $this->seller = Artist::factory()->create();
        $this->variant = $this->variantFor($this->seller);
    }

    private function passes(mixed $value): bool
    {
        return Validator::make(['q' => $value], ['q' => [new WholeBomQuantity]])->passes();
    }

    public function test_the_rule_accepts_whole_numbers_in_any_representation(): void
    {
        foreach ([1, 2, '2', 2.0, '11.0000', '11.0', 99999999, ' 3 '] as $value) {
            $this->assertTrue($this->passes($value), 'harus lolos: '.var_export($value, true));
        }
    }

    public function test_the_rule_refuses_fractions_zero_negatives_empty_and_oversized_values(): void
    {
        foreach ([1.5, '0.25', '2.0001', 0, '0', -1, '-3', 'abc', 100000000, '1e3x'] as $value) {
            $this->assertFalse($this->passes($value), 'harus ditolak: '.var_export($value, true));
        }
    }

    public function test_an_empty_quantity_is_refused_by_the_required_rule_of_each_request(): void
    {
        $line = $this->addItems($this->variant, [['purchase_order_item_id' => $this->po($this->seller, 'ordered', [['type' => 'material', 'name' => 'Chain', 'price' => 500]])[0]->id]])->json('data.0');

        $this->putJson("/api/v1/bom/{$line['id']}", ['qty_needed' => ''])->assertStatus(422)->assertJsonValidationErrors('qty_needed');
    }

    public function test_the_refusal_message_is_localized(): void
    {
        app()->setLocale('en');
        $en = Validator::make(['q' => 1.5], ['q' => [new WholeBomQuantity]])->errors()->first('q');
        app()->setLocale('id');
        $id = Validator::make(['q' => 1.5], ['q' => [new WholeBomQuantity]])->errors()->first('q');

        $this->assertStringContainsStringIgnoringCase('whole number', $en);
        $this->assertStringContainsStringIgnoringCase('bilangan bulat', $id);
    }

    public function test_every_http_entry_point_refuses_a_fraction_with_the_same_error(): void
    {
        [$item, $other] = $this->po($this->seller, 'ordered', [
            ['type' => 'material', 'name' => 'Chain', 'price' => 500],
            ['type' => 'material', 'name' => 'Ring', 'price' => 300],
        ]);

        // tambah baris PO
        $this->addItems($this->variant, [['purchase_order_item_id' => $item->id, 'qty' => 1.5]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.qty');

        // ubah jumlah
        $line = $this->addItems($this->variant, [['purchase_order_item_id' => $item->id, 'qty' => 2]])->assertCreated()->json('data.0');
        $this->putJson("/api/v1/bom/{$line['id']}", ['qty_needed' => 1.5])->assertStatus(422)->assertJsonValidationErrors('qty_needed');
        $this->putJson("/api/v1/bom/{$line['id']}", ['qty_needed' => 3])->assertOk();

        // jalur legacy
        $material = Material::factory()->create();
        $this->postJson("/api/v1/variants/{$this->variant->id}/bom", ['material_id' => $material->id, 'qty_needed' => 2.5])
            ->assertStatus(422)->assertJsonValidationErrors('qty_needed');

        $this->assertDatabaseMissing('product_variant_bom_lines', ['product_variant_id' => $this->variant->id, 'material_id' => $material->id]);
        $this->assertSame(3.0, (float) ProductVariantBomLine::find($line['id'])->qty_needed);
    }

    public function test_a_stored_fractional_legacy_row_still_reads_costs_and_accepts_a_notes_only_edit(): void
    {
        $material = Material::factory()->create();
        // Data lama (sebelum aturan bilangan bulat): dibuat langsung di basis data.
        $legacy = ProductVariantBomLine::factory()->create([
            'product_variant_id' => $this->variant->id, 'material_id' => $material->id, 'qty_needed' => 2.5,
        ]);

        $payload = $this->getJson("/api/v1/variants/{$this->variant->id}/bom")->assertOk()->json();
        $this->assertSame('2.5000', $payload['data'][0]['qty_needed']);

        // Catatan saja boleh berubah: qty_needed tidak ikut dikirim.
        $this->putJson("/api/v1/bom/{$legacy->id}", ['notes' => 'catatan'])->assertOk();
        $this->assertSame('2.5000', (string) $legacy->fresh()->qty_needed);

        // Mengubah jumlahnya menuntut bilangan bulat.
        $this->putJson("/api/v1/bom/{$legacy->id}", ['qty_needed' => 3.5])->assertStatus(422);
        $this->putJson("/api/v1/bom/{$legacy->id}", ['qty_needed' => 3])->assertOk();
        $this->assertSame('3.0000', (string) $legacy->fresh()->qty_needed);
    }
}
