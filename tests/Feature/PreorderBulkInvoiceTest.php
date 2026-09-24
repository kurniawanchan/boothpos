<?php

namespace Tests\Feature;

use App\Mail\PreorderInvoiceMail;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 022-preorder-invoice-crud-overhaul (US5, FR-013/FR-014/FR-015).
 */
class PreorderBulkInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

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
            'sku' => 'BULKA0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
    }

    private function createPreorder(?string $email): int
    {
        $customer = Customer::factory()->create(['email' => $email]);

        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json('id');
    }

    public function test_bulk_invoices_returns_the_full_invoice_payload_for_each_requested_id(): void
    {
        $idA = $this->createPreorder('a@example.test');
        $idB = $this->createPreorder('b@example.test');

        $response = $this->postJson('/api/v1/preorders/bulk-invoices', [
            'preorder_ids' => [$idA, $idB],
            'document' => 'invoice',
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('store_identity', $data[0]);
        $this->assertArrayHasKey('payment_channels', $data[0]);
        $this->assertArrayHasKey('footer_text', $data[0]);
        $this->assertArrayHasKey('items', $data[0]);
    }

    public function test_bulk_email_sends_one_email_per_preorder_with_an_email_on_file(): void
    {
        Mail::fake();
        $idA = $this->createPreorder('a@example.test');
        $idB = $this->createPreorder(null);

        $response = $this->postJson('/api/v1/preorders/bulk-email', [
            'preorder_ids' => [$idA, $idB],
            'document' => 'invoice',
        ]);

        $response->assertOk();
        $results = collect($response->json('data'))->keyBy('preorder_id');
        $this->assertEquals('sent', $results[$idA]['status']);
        $this->assertEquals('skipped_no_email', $results[$idB]['status']);

        Mail::assertSent(PreorderInvoiceMail::class, 1);
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $idA, 'trigger' => 'bulk_invoice_email', 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $idB, 'trigger' => 'bulk_invoice_email', 'status' => 'skipped_no_email',
        ]);
    }

    public function test_bulk_email_reports_skipped_not_configured_when_mail_default_is_log(): void
    {
        config(['mail.default' => 'log']);
        $id = $this->createPreorder('a@example.test');

        $response = $this->postJson('/api/v1/preorders/bulk-email', [
            'preorder_ids' => [$id],
            'document' => 'invoice',
        ]);

        $response->assertOk();
        $this->assertEquals('skipped_not_configured', $response->json('data.0.status'));
    }
}
