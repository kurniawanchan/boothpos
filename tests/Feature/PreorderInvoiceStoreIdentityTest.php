<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 022-preorder-invoice-crud-overhaul (Foundational, T005) — menguji
 * BuildsInvoiceDocument lewat endpoint invoice yang sungguhan
 * memakainya (GET /preorders/{id}/invoice), bukan trait secara terisolasi
 * (trait tidak punya kontrak publik sendiri untuk diuji langsung).
 */
class PreorderInvoiceStoreIdentityTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'INVID0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorderAndFetchInvoice(): TestResponse
    {
        $created = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated();

        return $this->getJson("/api/v1/preorders/{$created->json('id')}/invoice");
    }

    public function test_invoice_includes_store_identity_payment_channels_and_footer_when_configured(): void
    {
        Setting::create(['key' => 'store_name', 'value' => 'Sakana Fridge', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'store_contact_person', 'value' => 'Budi', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'store_contact_phone', 'value' => '0812xxxx', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'store_contact_email', 'value' => 'budi@example.test', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'store_address', 'value' => 'Jl. Contoh No. 1', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'receipt_footer_text', 'value' => 'Terima kasih!', 'type' => 'string', 'group' => 'store']);

        $channel = PaymentChannel::factory()->create([
            'is_active' => true, 'account_number' => '1234567890', 'display_order' => 1,
        ]);

        $response = $this->createPreorderAndFetchInvoice();

        $response->assertOk();
        $this->assertEquals('Sakana Fridge', $response->json('store_identity.name'));
        $this->assertEquals('Budi', $response->json('store_identity.contact_person'));
        $this->assertEquals('0812xxxx', $response->json('store_identity.contact_phone'));
        $this->assertEquals('budi@example.test', $response->json('store_identity.contact_email'));
        $this->assertEquals('Jl. Contoh No. 1', $response->json('store_identity.address'));
        $this->assertEquals('Terima kasih!', $response->json('footer_text'));

        // research.md Decision 2 — nomor rekening TIDAK disamarkan di
        // invoice, berbeda dengan PaymentChannelController::index() untuk
        // kasir non-privileged.
        $channels = $response->json('payment_channels');
        $this->assertCount(1, $channels);
        $this->assertEquals('1234567890', $channels[0]['account_number']);
        $this->assertEquals($channel->id, $channels[0]['id']);
    }

    public function test_invoice_omits_unset_store_identity_fields_rather_than_empty_strings(): void
    {
        $response = $this->createPreorderAndFetchInvoice();

        $response->assertOk();
        $this->assertNull($response->json('store_identity.contact_person'));
        $this->assertNull($response->json('store_identity.address'));
        $this->assertNull($response->json('footer_text'));
        $this->assertSame([], $response->json('payment_channels'));
    }

    public function test_logo_is_omitted_when_receipt_show_logo_is_disabled(): void
    {
        Setting::create(['key' => 'store_logo_path', 'value' => 'store-logos/logo.png', 'type' => 'string', 'group' => 'store']);
        Setting::create(['key' => 'receipt_show_logo', 'value' => 'false', 'type' => 'boolean', 'group' => 'store']);

        $response = $this->createPreorderAndFetchInvoice();

        $response->assertOk();
        $this->assertNull($response->json('store_identity.logo_url'));
    }
}
