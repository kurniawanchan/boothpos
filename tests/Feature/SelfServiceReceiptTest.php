<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\SelfServiceOrder;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US3 — struk pelanggan: bentuk daftar-putih, status terbaru, nomor POS sesudah dikirim, dan token acak.
 */
class SelfServiceReceiptTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    private SelfServiceOrder $order;

    private $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
        $this->item = $this->makeMenuItem(categoryStation: $this->makeStation('Dapur'), stock: 10, price: 20000);
        $this->order = $this->ssPlaceOrder([['variant_id' => $this->item->id, 'qty' => 2, 'note' => 'tanpa gula']]);
    }

    private function receipt(?string $token = null)
    {
        return $this->getJson('/api/v1/self-service/receipts/'.($token ?? $this->order->receipt_token));
    }

    public function test_the_receipt_carries_what_the_customer_needs_and_nothing_else(): void
    {
        $outlet = Outlet::findOrFail($this->order->outlet_id);

        $res = $this->receipt()->assertOk()
            ->assertJsonPath('data.reference_no', $this->order->reference_no)
            ->assertJsonPath('data.status', 'awaiting_verification')
            ->assertJsonPath('data.table_name', 'Meja 5')
            ->assertJsonPath('data.service_type', 'dine_in')
            ->assertJsonPath('data.subtotal', '40000.00')
            ->assertJsonPath('data.total_amount', '40000.00')
            ->assertJsonPath('data.pos_order_number', null)
            ->assertJsonPath('data.reject_reason', null)
            ->assertJsonPath('data.customer_name', null)
            ->assertJsonPath('data.payment.method', 'QRIS')
            ->assertJsonPath('data.payment.status', 'waiting_verification')
            ->assertJsonPath('data.lines.0.qty', 2)
            ->assertJsonPath('data.lines.0.note', 'tanpa gula')
            ->assertJsonPath('data.store.name', $outlet->name);

        // Daftar putih: tidak ada id internal, HPP, bukti, token, atau data pelanggan.
        $raw = $res->getContent();
        foreach (['cost_price', 'proof', 'receipt_token', 'customer_phone', 'customer_email', 'outlet_id', 'table_id', 'verified_by', 'verify_reference', 'local_ref', 'variant_id', 'data_mode'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "struk publik tidak boleh memuat {$forbidden}");
        }
    }

    public function test_the_status_follows_the_staff_actions(): void
    {
        $owner = $this->ssOwner();
        $this->actingAs($owner, 'sanctum');
        $this->makeShift($owner);

        $this->postJson("/api/v1/self-service/orders/{$this->order->id}/verify", ['reference' => 'BCA-1234'])->assertOk();
        $this->receipt()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.payment.status', 'verified')->assertJsonPath('data.pos_order_number', null);

        $number = $this->postJson("/api/v1/self-service/orders/{$this->order->id}/dispatch")->assertOk()->json('order.order_number');
        $this->receipt()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.pos_order_number', $number);
    }

    public function test_a_rejected_receipt_shows_the_reason(): void
    {
        $this->actingAs($this->ssOwner(), 'sanctum');
        $this->postJson("/api/v1/self-service/orders/{$this->order->id}/reject", ['reason' => 'Bukti tidak cocok'])->assertOk();

        $this->receipt()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Bukti tidak cocok')
            ->assertJsonPath('data.payment.status', 'rejected');
    }

    public function test_it_is_a_snapshot_not_a_live_view_of_the_menu(): void
    {
        $this->item->update(['sell_price' => 99000]);
        $this->item->product->update(['name' => 'Nama Baru']);

        $this->receipt()->assertJsonPath('data.lines.0.unit_price', '20000.00')->assertJsonPath('data.total_amount', '40000.00');
    }

    public function test_tax_label_appears_only_when_the_order_has_tax(): void
    {
        $this->receipt()->assertJsonPath('data.tax_label', null)->assertJsonPath('data.tax_amount', '0.00');

        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'tax_label'], ['value' => 'PB1', 'type' => 'string']);
        $taxed = $this->ssPlaceOrder([['variant_id' => $this->item->id, 'qty' => 1]]);

        $this->receipt($taxed->receipt_token)->assertJsonPath('data.tax_label', 'PB1')->assertJsonPath('data.tax_amount', '2000.00')->assertJsonPath('data.total_amount', '22000.00');
    }

    public function test_the_store_block_uses_the_outlet_of_the_order_and_never_leaks_internal_contact(): void
    {
        $outlet = Outlet::findOrFail($this->order->outlet_id);
        $outlet->forceFill(['address' => 'Jl. Mawar 1', 'contact_phone' => '021-555', 'contact_email' => 'rahasia@toko.test', 'notes' => 'catatan internal'])->save();

        $res = $this->receipt()->assertOk()->assertJsonPath('data.store.address', 'Jl. Mawar 1')->assertJsonPath('data.store.phone', '021-555');

        $this->assertStringNotContainsString('rahasia@toko.test', $res->getContent());
        $this->assertStringNotContainsString('catatan internal', $res->getContent());
    }

    public function test_the_token_is_random_long_and_an_id_or_guess_finds_nothing(): void
    {
        $this->assertSame(40, strlen($this->order->receipt_token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $this->order->receipt_token);

        $this->receipt((string) $this->order->id)->assertNotFound();
        $this->receipt(str_repeat('a', 40))->assertNotFound();
        $this->receipt(strtoupper($this->order->receipt_token))->assertNotFound();
        $this->receipt('x')->assertNotFound();
    }

    public function test_an_unknown_receipt_gives_one_friendly_answer(): void
    {
        $this->receipt(str_repeat('z', 40))->assertNotFound()->assertJsonPath('code', 'receipt_not_found');
    }

    public function test_a_receipt_from_the_other_data_mode_is_not_found(): void
    {
        SelfServiceOrder::withoutGlobalScopes()->whereKey($this->order->id)->update(['data_mode' => 'demo']);

        $this->receipt()->assertNotFound();
    }

    public function test_it_works_without_login_and_is_rate_limited(): void
    {
        $this->app['auth']->forgetGuards();
        $this->receipt()->assertOk();

        $codes = [];
        for ($i = 0; $i < 125; $i++) {
            $codes[] = $this->receipt()->getStatusCode();
        }
        $this->assertContains(429, $codes);
    }

    public function test_a_customer_name_appears_only_when_given(): void
    {
        SelfServiceOrder::withoutGlobalScopes()->whereKey($this->order->id)->update(['customer_name' => 'Budi', 'customer_phone' => '081234567890', 'customer_email' => 'budi@example.test']);

        $res = $this->receipt()->assertOk()->assertJsonPath('data.customer_name', 'Budi');
        $this->assertStringNotContainsString('081234567890', $res->getContent());
        $this->assertStringNotContainsString('budi@example.test', $res->getContent());
    }
}
