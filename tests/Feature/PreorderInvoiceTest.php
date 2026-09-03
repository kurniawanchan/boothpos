<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify (US2) — GET /preorders/{id}/invoice.
 */
class PreorderInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makePreorder(): Preorder
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);
        $variant = $product->variants()->create(['sku' => 'INVKYINV0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        return Preorder::findOrFail($response->json('id'));
    }

    #[DataProvider('openStatuses')]
    public function test_open_statuses_are_labeled_invoice_with_outstanding(string $status): void
    {
        $preorder = $this->makePreorder();
        if ($status !== 'ordered') {
            $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => $status]);
        }

        $response = $this->getJson("/api/v1/preorders/{$preorder->id}/invoice");

        $response->assertOk();
        $this->assertSame('invoice', $response->json('document_type'));
        $this->assertSame('100000.00', $response->json('outstanding'));
    }

    public static function openStatuses(): array
    {
        return [['ordered'], ['dp_paid'], ['arrived']];
    }

    #[DataProvider('paidStatuses')]
    public function test_paid_statuses_are_labeled_receipt(string $status): void
    {
        $preorder = $this->makePreorder();
        // dp_paid -> arrived -> settled(->handed_over) requires a full payment first for settled.
        $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'dp_paid']);
        $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'arrived']);
        $this->postJson("/api/v1/preorders/{$preorder->id}/payments", ['method' => 'cash', 'amount' => 100000]);
        $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'settled']);
        if ($status === 'handed_over') {
            $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'handed_over']);
        }

        $response = $this->getJson("/api/v1/preorders/{$preorder->id}/invoice");

        $response->assertOk();
        $this->assertSame('receipt', $response->json('document_type'));
    }

    public static function paidStatuses(): array
    {
        return [['settled'], ['handed_over']];
    }

    public function test_cancelled_preorder_document_type_is_cancelled(): void
    {
        $preorder = $this->makePreorder();
        $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'cancelled']);

        $response = $this->getJson("/api/v1/preorders/{$preorder->id}/invoice");

        $response->assertOk();
        $this->assertSame('cancelled', $response->json('document_type'));
    }

    public function test_invoice_404s_for_a_preorder_in_the_other_data_mode(): void
    {
        $preorder = $this->makePreorder();

        // Directly flip the row's data_mode to simulate cross-mode access —
        // the active mode is 'live' (test default), so this preorder becomes
        // invisible to the Eloquent global scope.
        \Illuminate\Support\Facades\DB::table('preorders')->where('id', $preorder->id)->update(['data_mode' => 'demo']);

        $response = $this->getJson("/api/v1/preorders/{$preorder->id}/invoice");

        $response->assertNotFound();
    }
}
