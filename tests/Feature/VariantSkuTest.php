<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Spec 045: pemilik mengetik nomor urut SKU; kode produk tetap dari server. */
class VariantSkuTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artist = Artist::factory()->create(['code' => 'SPF']);
        $this->category = Category::factory()->create(['code' => 'PI']);
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    /** @param array<int, string> $skus */
    private function product(string $prefix, array $skus = []): Product
    {
        $product = Product::factory()->create([
            'artist_id' => $this->artist->id,
            'category_id' => $this->category->id,
            'code_prefix' => $prefix,
            'product_segment' => substr($prefix, -1),
        ]);
        foreach ($skus as $sku) {
            $product->variants()->create(['sku' => $sku, 'variant_name' => $sku, 'sell_price' => 15000, 'current_stock' => 0]);
        }

        return $product;
    }

    private function setSequence(ProductVariant $variant, string $sequence): TestResponse
    {
        return $this->putJson("/api/v1/variants/{$variant->id}", ['sequence' => $sequence]);
    }

    /** T014 — nomor diketik: kode tetap, nomor berubah, flag tertandai. */
    public function test_typing_a_number_keeps_the_code_and_marks_the_sku_as_typed(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        $this->setSequence($variant, '7')->assertOk();

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id, 'sku' => 'SPF-PI-S-7', 'sku_is_typed' => true,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'code_prefix' => 'SPF-PI-S']);
    }

    public function test_leading_zeros_are_dropped_when_the_number_is_saved(): void
    {
        $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        $this->setSequence($variant, '007')->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-PI-S-7']);
    }

    public function test_a_blank_or_unchanged_number_changes_nothing(): void
    {
        $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        $this->setSequence($variant, '1')->assertOk();
        $this->putJson("/api/v1/variants/{$variant->id}", ['variant_name' => 'Baru'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-PI-S-1', 'sku_is_typed' => false]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'sku_changed', 'entity_id' => $variant->id]);
    }

    /** T015 — varian baru dengan nomor diketik, dan tanpa nomor tetap dibuat server. */
    public function test_a_new_variant_takes_the_typed_number_and_a_blank_one_is_generated(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1']);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'Typed', 'sell_price' => 15000, 'sequence' => '9',
        ])->assertCreated()->assertJsonPath('sku', 'SPF-PI-S-9');

        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-PI-S-9', 'sku_is_typed' => true]);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'variant_name' => 'Generated', 'sell_price' => 15000,
        ])->assertCreated()->assertJsonPath('sku', 'SPF-PI-S-10');

        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-PI-S-10', 'sku_is_typed' => false]);
    }

    /** T016 — audit ada dalam transaksi yang sama; snapshot penjualan tidak berubah. */
    public function test_the_change_is_audited_and_a_past_sale_keeps_its_sku(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $event = Event::factory()->create(['status' => 'active']);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);
        $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();
        $variant->update(['current_stock' => 10, 'cost_price' => 5000]);

        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->setSequence($variant, '7')->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sku_changed', 'entity_type' => 'ProductVariant', 'entity_id' => $variant->id,
        ]);
        $this->assertSame('SPF-PI-S-1', DB::table('order_items')->where('variant_id', $variant->id)->value('sku_snapshot'));
    }

    /** T022 — penolakan: huruf, nol, terlalu panjang, dipakai varian lain (termasuk dihapus dan mode lain). */
    public function test_letters_zero_and_symbols_are_refused_with_the_message_on_sku(): void
    {
        $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        foreach (['A7', '0', '7-1'] as $bad) {
            $this->setSequence($variant, $bad)->assertStatus(422)->assertJsonValidationErrors('sku');
        }

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-PI-S-1', 'sku_is_typed' => false]);
    }

    /** Spec 046: SKU sampai 20 karakter diterima; 21 karakter ditolak pada kolom sku. */
    public function test_a_sku_longer_than_twenty_characters_is_refused(): void
    {
        $this->product('SPF-PI-SPF', ['SPF-PI-SPF-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-SPF-1')->firstOrFail();

        $this->setSequence($variant, '123456789')->assertOk();
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-PI-SPF-123456789']);

        $this->setSequence($variant->fresh(), '1234567890')->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    public function test_a_number_used_by_another_variant_is_refused(): void
    {
        $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-7']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        $this->setSequence($variant, '7')->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    /** Spec 045 FR-016: nomor varian yang sudah dihapus boleh dipakai lagi. */
    public function test_a_number_used_by_a_deleted_variant_can_be_typed_again(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-7']);
        $product->variants()->where('sku', 'SPF-PI-S-7')->firstOrFail()->delete();
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();

        $this->setSequence($variant, '7')->assertOk();
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'SPF-PI-S-7']);
    }

    public function test_a_number_used_by_another_data_mode_is_refused(): void
    {
        $this->product('SPF-PI-S', ['SPF-PI-S-1']);
        $variant = ProductVariant::where('sku', 'SPF-PI-S-1')->firstOrFail();
        ModeGate::runAs('demo', fn () => $this->product('SPF-PI-X', ['SPF-PI-S-7']));

        $this->setSequence($variant, '7')->assertStatus(422)->assertJsonValidationErrors('sku');
    }

    /** @param array<int, array{0: int, 1: string}> $items [variant_id, sequence] */
    private function batch(Product $product, array $items): TestResponse
    {
        return $this->putJson("/api/v1/products/{$product->id}/variant-sequences", [
            'items' => array_map(fn ($i) => ['variant_id' => $i[0], 'sequence' => $i[1]], $items),
        ]);
    }

    private function idOf(string $sku): int
    {
        return ProductVariant::withTrashed()->where('sku', $sku)->value('id');
    }

    /** Geser nomor naik satu-satu dalam satu simpan: 1→2, 2→3, 3→4 (tidak boleh bentrok). */
    public function test_shifting_numbers_up_in_one_save_succeeds(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-2', 'SPF-PI-S-3']);

        $this->batch($product, [
            [$this->idOf('SPF-PI-S-1'), '2'],
            [$this->idOf('SPF-PI-S-2'), '3'],
            [$this->idOf('SPF-PI-S-3'), '4'],
        ])->assertOk();

        $skus = ProductVariant::where('product_id', $product->id)->orderBy('sku')->pluck('sku')->all();
        $this->assertSame(['SPF-PI-S-2', 'SPF-PI-S-3', 'SPF-PI-S-4'], $skus);
        $this->assertSame(3, ProductVariant::where('product_id', $product->id)->where('sku_is_typed', true)->count());
    }

    public function test_swapping_two_numbers_succeeds(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-2']);
        $one = $this->idOf('SPF-PI-S-1');
        $two = $this->idOf('SPF-PI-S-2');

        $this->batch($product, [[$one, '2'], [$two, '1']])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $one, 'sku' => 'SPF-PI-S-2']);
        $this->assertDatabaseHas('product_variants', ['id' => $two, 'sku' => 'SPF-PI-S-1']);
    }

    /** Nomor yang dipakai varian yang TIDAK ikut digeser ditolak; tidak ada yang tersimpan. */
    public function test_a_clash_with_a_variant_that_stays_is_refused_and_nothing_changes(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-2', 'SPF-PI-S-3']);
        $first = $this->idOf('SPF-PI-S-1');

        $this->batch($product, [[$first, '3']])
            ->assertStatus(422)
            ->assertJsonValidationErrors("sequences.{$first}");

        $skus = ProductVariant::where('product_id', $product->id)->orderBy('sku')->pluck('sku')->all();
        $this->assertSame(['SPF-PI-S-1', 'SPF-PI-S-2', 'SPF-PI-S-3'], $skus);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'sku_changed']);
    }

    public function test_two_variants_given_the_same_number_are_refused(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-2']);

        $this->batch($product, [
            [$this->idOf('SPF-PI-S-1'), '5'],
            [$this->idOf('SPF-PI-S-2'), '5'],
        ])->assertStatus(422);

        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-PI-S-1']);
    }

    /** Spec 045 FR-016: nomor varian yang dihapus boleh dipakai dalam satu simpan batch. */
    public function test_a_number_used_by_a_deleted_variant_can_be_used_in_a_batch(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-4']);
        ProductVariant::where('sku', 'SPF-PI-S-4')->firstOrFail()->delete();

        $this->batch($product, [[$this->idOf('SPF-PI-S-1'), '4']])->assertOk();
        $this->assertDatabaseHas('product_variants', ['sku' => 'SPF-PI-S-4', 'sku_is_typed' => true]);
    }

    public function test_each_changed_variant_gets_one_audit_entry(): void
    {
        $product = $this->product('SPF-PI-S', ['SPF-PI-S-1', 'SPF-PI-S-2']);

        $this->batch($product, [
            [$this->idOf('SPF-PI-S-1'), '2'],
            [$this->idOf('SPF-PI-S-2'), '3'],
        ])->assertOk();

        $this->assertSame(2, \DB::table('activity_logs')->where('action', 'sku_changed')->count());
    }
}
