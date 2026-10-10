<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028-restaurant-dine-in-ordering, research.md Keputusan 5 — pagar yang
 * mencegah order berstatus 'open' (tab belum dibayar) terhitung sebagai
 * uang. Satu test per baris 1–6 pada tabel itu. Tab dibuat langsung lewat
 * Order::create() supaya pagar ini terbukti SEBELUM TabService ada.
 */
class TabImpactGuardsTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    // --- 1. Tutup shift: kas yang diharapkan ---------------------------

    /**
     * Dua lapis pertahanan, keduanya diuji:
     *  (a) FR-011 — shift TIDAK boleh ditutup selagi masih ada tab terbuka
     *      (ditambahkan di user story 2);
     *  (b) filter eksplisit status = 'completed' pada rumus kas (pertahanan
     *      berlapis). CATATAN JUJUR: karena (a) memblokir penutupan, tidak ada
     *      test yang bisa membedakan filter baru dari yang lama — (b) hanya
     *      terjaga lewat ulasan kode; test kedua di bawah hanya memastikan
     *      rumus kas tetap per-shift.
     */
    public function test_a_shift_with_an_open_tab_cannot_be_closed(): void
    {
        $session = $this->makeShift($this->cashier, ['opening_cash' => 100000]);
        $tab = $this->makeOpenOrder($session, $this->cashier);
        $this->addCashPaymentTo($tab, 20000);

        $this->actingAs($this->cashier, 'sanctum');
        $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000])
            ->assertStatus(409)
            ->assertJsonPath('open_tabs.0.id', $tab->id);

        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_expected_cash_counts_only_the_shifts_own_sales(): void
    {
        $session = $this->makeShift($this->cashier, ['opening_cash' => 100000]);
        $other = $this->makeShift($this->owner);
        // Tab terbuka milik SHIFT LAIN dengan uang tunai: bukan milik shift ini.
        $foreign = $this->makeOpenOrder($other, $this->owner);
        $this->addCashPaymentTo($foreign, 20000);

        $this->actingAs($this->cashier, 'sanctum');
        $response = $this->postJson("/api/v1/sessions/{$session->id}/close", ['closing_cash' => 100000]);

        $response->assertOk();
        $this->assertEquals(100000, $response->json('expected_cash'));
        $this->assertEquals(0, $response->json('cash_difference'));
    }

    // --- 2. Void order terbuka -----------------------------------------

    public function test_voiding_an_open_order_is_refused_and_returns_no_stock(): void
    {
        $session = $this->makeShift($this->cashier);
        $variant = $this->makeVariant(stock: 10);
        $tab = $this->makeOpenOrder($session, $this->cashier);
        $this->addLineTo($tab, $variant, 2);

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$tab->id}/void", ['reason' => 'salah'])->assertStatus(409);

        $this->assertSame('open', $tab->fresh()->status);
        $this->assertSame(10, $variant->fresh()->current_stock);
        $this->assertDatabaseMissing('stock_movements', ['variant_id' => $variant->id, 'type' => 'return']);
    }

    // --- 3. local_ref milik order terbuka ------------------------------

    public function test_a_local_ref_that_belongs_to_an_open_order_is_not_returned_as_a_sale(): void
    {
        $session = $this->makeShift($this->cashier);
        $variant = $this->makeVariant(stock: 10, price: 25000);
        $ref = $this->newLocalRef();
        $this->makeOpenOrder($session, $this->cashier, ['local_ref' => $ref]);

        $this->actingAs($this->cashier, 'sanctum');
        $response = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => $ref,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 25000]],
        ]);

        $response->assertStatus(409)->assertJsonStructure(['message', 'errors' => ['local_ref']]);
        $this->assertSame(1, Order::count());
        $this->assertSame(10, $variant->fresh()->current_stock);
    }

    // --- 4. Daftar order -----------------------------------------------

    public function test_the_order_list_never_includes_open_orders(): void
    {
        $session = $this->makeShift($this->cashier);
        $open = $this->makeOpenOrder($session, $this->cashier);
        $done = $this->makeOpenOrder($session, $this->cashier, ['status' => 'completed']);

        $this->actingAs($this->cashier, 'sanctum');

        $ids = collect($this->getJson('/api/v1/orders')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($done->id));
        $this->assertFalse($ids->contains($open->id));

        // Bahkan filter eksplisit tidak boleh membocorkan tab terbuka lewat jalur ini.
        $this->assertSame([], $this->getJson('/api/v1/orders?status=open')->assertOk()->json('data'));
    }

    // --- 5. Struk -------------------------------------------------------

    public function test_a_receipt_cannot_be_issued_for_an_unpaid_open_order(): void
    {
        $session = $this->makeShift($this->cashier);
        $open = $this->makeOpenOrder($session, $this->cashier);

        $this->actingAs($this->cashier, 'sanctum');
        $this->getJson("/api/v1/orders/{$open->id}/receipt")->assertStatus(409);
    }

    // --- 6. Riwayat pelanggan ------------------------------------------

    public function test_customer_history_excludes_open_orders(): void
    {
        $session = $this->makeShift($this->cashier);
        $customer = Customer::factory()->create();
        $open = $this->makeOpenOrder($session, $this->cashier, ['customer_id' => $customer->id]);
        $done = $this->makeOpenOrder($session, $this->cashier, ['customer_id' => $customer->id, 'status' => 'completed']);

        $this->actingAs($this->cashier, 'sanctum');
        $rows = collect($this->getJson("/api/v1/customers/{$customer->id}/transactions")->assertOk()->json('data'))
            ->where('type', 'order');

        $this->assertTrue($rows->pluck('id')->contains($done->id));
        $this->assertFalse($rows->pluck('id')->contains($open->id));
    }
}
