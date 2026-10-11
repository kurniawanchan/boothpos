<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T002) — karakterisasi aturan "bisa dipesan" SEBELUM digantikan oleh
 * App\Support\MenuAvailability. Mengunci perilaku YANG BERLAKU HARI INI:
 *  - varian nonaktif ditolak di checkout POS dan di penambahan baris tab;
 *  - varian aktif diterima;
 *  - status AKTIF PRODUK sendiri belum diperiksa oleh jalur ini (celah yang diwariskan: mendiskontinu
 *    produk tidak menghalangi pesanan — fitur 043 menutupnya lewat MenuAvailability dan tes ini
 *    diperbarui dengan jelas pada saat itu, bukan dilemahkan).
 */
class OrderAvailabilityCharacterizationTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    private \App\Models\CashierSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
    }

    private function checkout(int $variantId)
    {
        return $this->postJson('/api/v1/orders', [
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'items' => [['variant_id' => $variantId, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ]);
    }

    public function test_an_active_variant_can_be_checked_out(): void
    {
        $this->checkout($this->makeVariant(stock: 5, price: 10000)->id)->assertCreated();
    }

    public function test_an_inactive_variant_is_refused_at_checkout_and_nothing_is_recorded(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $variant->update(['is_active' => false]);

        $this->checkout($variant->id)->assertStatus(409);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $variant->fresh()->current_stock);
    }

    public function test_an_inactive_variant_is_refused_as_a_tab_line(): void
    {
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $variant->update(['is_active' => false]);
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');

        $this->apiAddLine($tab, $variant->id, 1)->assertStatus(409);

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_discontinued_parent_product_is_now_refused_the_inherited_gap_is_closed(): void
    {
        // 043 (T017) — SENGAJA diubah: dulu pesanan untuk varian aktif milik produk nonaktif diterima.
        // MenuAvailability kini memeriksa produk induk; mendiskontinu produk benar-benar menghalangi pesanan baru.
        $variant = $this->makeVariant(stock: 5, price: 10000);
        $variant->product->update(['is_active' => false]);

        $this->checkout($variant->id)->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);
    }
}
