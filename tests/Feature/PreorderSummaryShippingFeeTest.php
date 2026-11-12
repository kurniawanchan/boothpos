<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 068-slip-notes-shipping-fee-stat (US2, T007) — `GET /preorders/summary` membawa
 * `total_shipping_fee`: jumlah `shipping_cost` pre-order Mail Order (courier) yang
 * cocok dengan filter, tanpa yang 'cancelled' (research D3/D4).
 *
 * Pre-order dibuat langsung lewat model: yang diuji di sini adalah agregat, bukan
 * alur pembuatan pre-order.
 */
class PreorderSummaryShippingFeeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
    }

    private function makePreorder(
        Customer $customer,
        string $fulfillment = 'courier',
        float $shippingCost = 0,
        string $status = 'ordered',
        array $extra = [],
    ): Preorder {
        $this->seq++;

        return Preorder::create(array_merge([
            'preorder_number' => sprintf('PO-20261112-%04d', $this->seq),
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'status' => $status,
            'fulfillment' => $fulfillment,
            'subtotal' => 100000,
            'shipping_cost' => $shippingCost,
            'total_amount' => 100000 + $shippingCost,
        ], $extra));
    }

    private function fee(string $query = ''): string
    {
        return $this->getJson('/api/v1/preorders/summary'.$query)->assertOk()->json('total_shipping_fee');
    }

    public function test_without_filters_it_equals_the_hand_sum_of_courier_fees_as_a_two_decimal_string(): void
    {
        $customer = Customer::factory()->create();
        $this->makePreorder($customer, 'courier', 20000);
        $this->makePreorder($customer, 'courier', 30000);
        $this->makePreorder($customer, 'pickup', 0);

        $this->assertSame('50000.00', $this->fee());
    }

    public function test_status_and_customer_filters_narrow_the_sum(): void
    {
        $a = Customer::factory()->create();
        $b = Customer::factory()->create();
        $this->makePreorder($a, 'courier', 20000, 'ordered');
        $this->makePreorder($a, 'courier', 30000, 'dp_paid');
        $this->makePreorder($b, 'courier', 7500, 'ordered');

        $this->assertSame('57500.00', $this->fee());
        $this->assertSame('27500.00', $this->fee('?status[]=ordered'));
        $this->assertSame('50000.00', $this->fee('?customer_id[]='.$a->id));
        $this->assertSame('20000.00', $this->fee('?customer_id[]='.$a->id.'&status[]=ordered'));
    }

    public function test_a_filter_that_matches_nothing_reads_zero_not_null(): void
    {
        $this->makePreorder(Customer::factory()->create(), 'courier', 20000);

        $this->assertSame('0.00', $this->fee('?search=tidak-ada-yang-cocok-xyz'));
    }

    public function test_a_cancelled_order_is_not_counted_but_still_counts_as_a_transaction(): void
    {
        $customer = Customer::factory()->create();
        $this->makePreorder($customer, 'courier', 20000, 'ordered');
        $this->makePreorder($customer, 'courier', 99000, 'cancelled');

        $response = $this->getJson('/api/v1/preorders/summary')->assertOk();

        $this->assertSame('20000.00', $response->json('total_shipping_fee'));
        $this->assertSame(2, $response->json('transaction_count'));
    }

    public function test_a_pickup_order_never_contributes_even_with_a_stored_fee(): void
    {
        $customer = Customer::factory()->create();
        $this->makePreorder($customer, 'courier', 20000);
        $this->makePreorder($customer, 'pickup', 45000);

        $this->assertSame('20000.00', $this->fee());
    }

    public function test_demo_mode_preorders_are_not_counted_in_live_mode(): void
    {
        $customer = Customer::factory()->create();
        ModeGate::runAs('live', fn () => $this->makePreorder($customer, 'courier', 20000));
        ModeGate::runAs('demo', fn () => $this->makePreorder(Customer::factory()->create(), 'courier', 88000));

        $this->assertSame('20000.00', ModeGate::runAs('live', fn () => $this->fee()));
    }

    public function test_the_existing_summary_keys_are_unchanged(): void
    {
        $customer = Customer::factory()->create();
        $this->makePreorder($customer, 'courier', 20000);

        $response = $this->getJson('/api/v1/preorders/summary')->assertOk();

        $this->assertSame(1, $response->json('transaction_count'));
        $this->assertSame('120000.00', $response->json('grand_total'));
        $this->assertSame('120000.00', $response->json('total_outstanding'));
        $this->assertCount(6, $response->json('by_status'));
    }

    /**
     * T010 / research D3 — kasus nyata, bukan data buatan: Mail Order diubah jadi Ambil Sendiri
     * lewat PUT tanpa `shipping_cost` (persis yang dikirim layar edit: ongkir hanya dikirim
     * untuk courier). Service mempertahankan ongkir lama, jadi pre-order pickup BISA menyimpan
     * ongkir — dan statistik tetap mengabaikannya (FR-010), sementara total_amount masih
     * memuatnya (perilaku lama, di luar lingkup fitur ini).
     */
    public function test_switching_a_mail_order_to_pickup_keeps_the_stored_fee_but_the_statistic_ignores_it(): void
    {
        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $variant = $product->variants()->create([
            'sku' => 'FEE-0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $customer = Customer::factory()->create();

        $id = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'courier',
            'shipping_cost' => 25000,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->assertCreated()->json('id');
        $this->assertSame('25000.00', $this->fee());

        $this->putJson("/api/v1/preorders/{$id}", [
            'fulfillment' => 'pickup',
            'courier_name' => null,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->assertOk();

        $row = Preorder::find($id);
        $this->assertSame('pickup', $row->fulfillment);
        $this->assertSame('25000.00', number_format((float) $row->shipping_cost, 2, '.', ''), 'ongkir lama masih tersimpan');
        $this->assertSame('0.00', $this->fee());
    }

    /**
     * 068 — urut kolom "Ongkos kirim": Mail Order menurut ongkir; Ambil Sendiri ("—" di layar,
     * walau ongkir lama tersimpan) SELALU di akhir, naik maupun turun, dan baris sama nilai
     * tetap berurutan lewat pengikat id.
     */
    public function test_list_sorts_by_shipping_fee_with_pickup_rows_always_last(): void
    {
        $customer = Customer::factory()->create();
        $mid = $this->makePreorder($customer, 'courier', 15000);
        $stalePickup = $this->makePreorder($customer, 'pickup', 99000); // ongkir lama: tidak boleh terselip di tengah
        $high = $this->makePreorder($customer, 'courier', 30000);
        $tieA = $this->makePreorder($customer, 'courier', 15000);
        $low = $this->makePreorder($customer, 'courier', 0);
        $pickup = $this->makePreorder($customer, 'pickup', 0);

        $ids = fn (string $dir) => collect($this->getJson("/api/v1/preorders?per_page=100&sort_by=shipping_cost&sort_dir={$dir}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(
            [$low->id, $mid->id, $tieA->id, $high->id, $stalePickup->id, $pickup->id],
            $ids('asc'),
        );
        $this->assertSame(
            [$high->id, $tieA->id, $mid->id, $low->id, $pickup->id, $stalePickup->id],
            $ids('desc'),
        );
    }

    /**
     * 068 — kolom "Ongkos kirim" menampilkan {kurir} {ongkir} {catatan}: baris daftar harus
     * membawa ketiganya (kurir = Preorder::courier_name, bukan shipments.courier_name).
     */
    public function test_list_rows_carry_courier_shipping_cost_and_notes_for_the_shipping_fee_column(): void
    {
        $preorder = $this->makePreorder(Customer::factory()->create(), 'courier', 25000, 'ordered', [
            'courier_name' => 'JNE', 'notes' => 'Fragile, wrap twice',
        ]);

        $row = collect($this->getJson('/api/v1/preorders?per_page=100')->assertOk()->json('data'))->firstWhere('id', $preorder->id);

        $this->assertSame('JNE', $row['courier_name']);
        $this->assertSame('25000.00', $row['shipping_cost']);
        $this->assertSame('Fragile, wrap twice', $row['notes']);
    }
}
