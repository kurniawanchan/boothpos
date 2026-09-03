<?php

namespace Tests\Feature;

use App\Mail\PreorderStatusMail;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 007-preorder-import-export-notify (US4) — status-change auto-notify and
 * manual resend, backed by PreorderNotification (research.md R6/R7).
 */
class PreorderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makePreorder(?string $customerEmail = 'siti@example.com'): Preorder
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true]);
        $variant = $product->variants()->create(['sku' => 'NOTKYNOT0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0]);
        $customer = Customer::factory()->create(['email' => $customerEmail]);

        $response = $this->postJson('/api/v1/preorders', [
            'customer_id' => $customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ]);

        return Preorder::findOrFail($response->json('id'));
    }

    public function test_status_change_sends_mail_and_logs_a_sent_notification(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $preorder = $this->makePreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'dp_paid']);

        $response->assertOk();
        Mail::assertSent(PreorderStatusMail::class);
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $preorder->id,
            'trigger' => 'status_change',
            'status' => 'sent',
        ]);
    }

    public function test_status_change_succeeds_even_if_mail_send_throws(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $preorder = $this->makePreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'dp_paid']);

        $response->assertOk();
        $this->assertSame('dp_paid', $preorder->fresh()->status);
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $preorder->id,
            'status' => 'failed',
        ]);
    }

    public function test_resend_endpoint_works_independent_of_status_change_and_is_forbidden_for_cashier(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $preorder = $this->makePreorder();

        // still acting as the cashier who created it — resend must 403
        $this->postJson("/api/v1/preorders/{$preorder->id}/notifications/resend")->assertStatus(403);

        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');

        $response = $this->postJson("/api/v1/preorders/{$preorder->id}/notifications/resend");

        $response->assertOk();
        $this->assertSame('sent', $response->json('status'));
        $this->assertDatabaseHas('preorder_notifications', [
            'preorder_id' => $preorder->id,
            'trigger' => 'manual_resend',
        ]);
    }

    public function test_show_includes_latest_notification(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $preorder = $this->makePreorder();
        $this->patchJson("/api/v1/preorders/{$preorder->id}/status", ['status' => 'dp_paid']);

        $response = $this->getJson("/api/v1/preorders/{$preorder->id}");

        $response->assertOk();
        $this->assertSame('sent', $response->json('latest_notification.status'));
        $this->assertSame('status_change', $response->json('latest_notification.trigger'));
    }
}
