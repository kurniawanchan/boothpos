<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PreorderNotification;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PreorderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify — Foundational (T006/T007): the two
 * "skip" branches of PreorderNotifier must never throw and must always
 * leave an audit row behind (research.md R5/R6).
 */
class PreorderNotifierSkipBranchesTest extends TestCase
{
    use RefreshDatabase;

    private function createPreorderFor(?string $customerEmail): \App\Models\Preorder
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);
        $variant = $product->variants()->create(['sku' => 'RYUKYFIG0002', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $customer = Customer::factory()->create(['email' => $customerEmail]);

        $response = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        return \App\Models\Preorder::findOrFail($response->json('id'));
    }

    public function test_skips_without_error_when_customer_has_no_email(): void
    {
        Mail::fake();
        $preorder = $this->createPreorderFor(null);

        $notification = app(PreorderNotifier::class)->notifyStatusChange($preorder, 'status_change');

        $this->assertSame('skipped_no_email', $notification->status);
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $preorder->id,
            'status' => 'skipped_no_email',
        ]);
        Mail::assertNothingSent();
    }

    public function test_skips_without_error_when_mail_is_not_configured(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $preorder = $this->createPreorderFor('siti@example.com');

        $notification = app(PreorderNotifier::class)->notifyStatusChange($preorder, 'status_change');

        $this->assertSame('skipped_not_configured', $notification->status);
        $this->assertSame('siti@example.com', $notification->recipient_email);
        Mail::assertNothingSent();
    }

    public function test_records_a_row_for_every_attempt(): void
    {
        Mail::fake();
        $preorder = $this->createPreorderFor(null);

        app(PreorderNotifier::class)->notifyStatusChange($preorder, 'status_change');

        $this->assertSame(1, PreorderNotification::where('preorder_id', $preorder->id)->count());
    }
}
