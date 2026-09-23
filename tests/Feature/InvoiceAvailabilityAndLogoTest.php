<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 023-event-availability-invoice-redesign (US2, FR-004; US4, FR-011/FR-012).
 */
class InvoiceAvailabilityAndLogoTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'AVLB0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    // --- US2: event_available_on_date ---------------------------------

    public function test_preorder_invoice_includes_resolved_available_on_date(): void
    {
        $event = Event::factory()->create([
            'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'available_on' => 'day_2',
        ]);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'event_id' => $event->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice");

        $response->assertOk()->assertJsonPath('event_available_on_date', '2026-11-02');
    }

    public function test_bulk_invoices_includes_resolved_available_on_date(): void
    {
        $event = Event::factory()->create([
            'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'available_on' => 'day_1',
        ]);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'event_id' => $event->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->postJson('/api/v1/preorders/bulk-invoices', [
            'preorder_ids' => [$preorder['id']], 'document' => 'invoice',
        ]);

        $response->assertOk()->assertJsonPath('data.0.event_available_on_date', '2026-11-01');
    }

    public function test_preorder_invoice_omits_available_on_date_when_event_has_none_set(): void
    {
        $event = Event::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2026-11-02']);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'event_id' => $event->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice");

        $response->assertOk()->assertJsonPath('event_available_on_date', null);
    }

    public function test_preorder_invoice_omits_available_on_date_when_no_event_linked(): void
    {
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $response = $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice");

        $response->assertOk()->assertJsonPath('event_available_on_date', null);
    }

    public function test_order_receipt_includes_resolved_available_on_date(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $event = Event::factory()->create([
            'status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'available_on' => 'day_2',
        ]);
        $session = CashierSession::factory()->create(['event_id' => $event->id, 'user_id' => $cashier->id, 'status' => 'open']);

        $stockedVariant = $this->variant->product->variants()->create([
            'sku' => 'AVLB0002', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 10,
        ]);

        $order = $this->postJson('/api/v1/orders', [
            'session_id' => $session->id,
            'local_ref' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['variant_id' => $stockedVariant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ])->assertCreated()->json();

        $response = $this->getJson("/api/v1/orders/{$order['id']}/receipt");

        $response->assertOk()->assertJsonPath('event_available_on_date', '2026-11-02');
    }

    // --- US4: store_logo_url -------------------------------------------

    public function test_settings_index_returns_resolved_store_logo_url(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('logo.png')->store('store-logo', 'public');
        Setting::updateOrCreate(['key' => 'store_logo_path'], ['value' => $path, 'type' => 'string', 'group' => 'receipt']);

        $response = $this->getJson('/api/v1/settings');

        $response->assertOk();
        $this->assertStringContainsString($path, $response->json('store_logo_url'));
    }

    public function test_settings_index_returns_null_store_logo_url_when_unset(): void
    {
        $response = $this->getJson('/api/v1/settings');

        $response->assertOk()->assertJsonPath('store_logo_url', null);
    }

    public function test_upload_store_logo_response_includes_resolved_url(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('logo.png');

        $response = $this->postJson('/api/v1/settings/store-logo', ['image' => $file]);

        $response->assertOk();
        $this->assertNotNull($response->json('store_logo_url'));
        $this->assertStringContainsString('store-logo/', $response->json('store_logo_url'));
    }
}
