<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penanda manual `dispatch_status` (invoice terkirim / pengiriman berjalan).
 * Fokus pada tiga janji: default 'pending', bisa diubah maju-mundur tanpa
 * menyentuh `status` utama/stok, dan bisa difilter di list.
 */
class PreorderDispatchStatusTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'DISP0001', 'sell_price' => 100000, 'cost_price' => 50000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(string $fulfillment = 'courier'): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => $fulfillment,
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertCreated()->json();
    }

    public function test_new_preorder_defaults_to_pending(): void
    {
        $preorder = $this->createPreorder();

        $this->assertSame('pending', $preorder['dispatch_status']);
        $this->getJson("/api/v1/preorders/{$preorder['id']}")
            ->assertOk()->assertJsonPath('dispatch_status', 'pending');
    }

    public function test_can_move_forward_and_back_without_touching_main_status_or_stock(): void
    {
        $preorder = $this->createPreorder();
        $url = "/api/v1/preorders/{$preorder['id']}/dispatch-status";

        $this->patchJson($url, ['dispatch_status' => 'invoice_sent'])
            ->assertOk()
            ->assertJsonPath('dispatch_status', 'invoice_sent')
            ->assertJsonPath('status', 'ordered');

        $this->patchJson($url, ['dispatch_status' => 'shipping'])
            ->assertOk()->assertJsonPath('dispatch_status', 'shipping');

        // Salah klik harus bisa dikoreksi mundur.
        $this->patchJson($url, ['dispatch_status' => 'pending'])
            ->assertOk()->assertJsonPath('dispatch_status', 'pending');

        $this->assertSame(0, $this->variant->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_response_still_carries_relations_present_reads(): void
    {
        $preorder = $this->createPreorder();

        // present() menyembunyikan relasi yang tak dimuat secara diam-diam —
        // pastikan endpoint ini tidak menghilangkan items/customer.
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'invoice_sent'])
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('customer.id', $this->customer->id);
    }

    public function test_invalid_value_is_rejected_with_422(): void
    {
        $preorder = $this->createPreorder();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'delivered'])
            ->assertStatus(422);
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", [])
            ->assertStatus(422);
    }

    public function test_cancelled_preorder_is_refused_with_409(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'cancelled'])->assertOk();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'shipping'])
            ->assertStatus(409);
        $this->assertDatabaseHas('preorders', ['id' => $preorder['id'], 'dispatch_status' => 'pending']);
    }

    public function test_list_can_be_filtered_by_one_or_more_dispatch_statuses(): void
    {
        $pending = $this->createPreorder();
        $sent = $this->createPreorder();
        $shipping = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$sent['id']}/dispatch-status", ['dispatch_status' => 'invoice_sent'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$shipping['id']}/dispatch-status", ['dispatch_status' => 'shipping'])->assertOk();

        $ids = fn (array $query) => collect($this->getJson('/api/v1/preorders?'.http_build_query($query))
            ->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$sent['id']], $ids(['dispatch_status' => ['invoice_sent']]));
        $this->assertSame(
            collect([$sent['id'], $shipping['id']])->sort()->values()->all(),
            $ids(['dispatch_status' => ['invoice_sent', 'shipping']]),
        );
        $this->assertSame([$pending['id']], $ids(['dispatch_status' => ['pending']]));
        $this->assertCount(3, $ids([]));
    }

    public function test_shipping_is_refused_for_a_pickup_preorder_but_invoice_sent_is_allowed(): void
    {
        $pickup = $this->createPreorder('pickup');
        $url = "/api/v1/preorders/{$pickup['id']}/dispatch-status";

        $this->patchJson($url, ['dispatch_status' => 'shipping'])->assertStatus(409);
        $this->assertDatabaseHas('preorders', ['id' => $pickup['id'], 'dispatch_status' => 'pending']);

        $this->patchJson($url, ['dispatch_status' => 'invoice_sent'])
            ->assertOk()->assertJsonPath('dispatch_status', 'invoice_sent');
    }

    public function test_editing_a_shipping_preorder_to_pickup_downgrades_it_to_invoice_sent(): void
    {
        $preorder = $this->createPreorder('courier');
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'shipping'])->assertOk();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'fulfillment' => 'pickup',
            'courier_name' => null, // pickup tidak boleh membawa kurir (aturan 021)
            'items' => [['id' => $preorder['items'][0]['id'], 'variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('preorders', ['id' => $preorder['id'], 'fulfillment' => 'pickup', 'dispatch_status' => 'invoice_sent']);
    }

    public function test_editing_keeps_dispatch_status_when_it_is_not_shipping(): void
    {
        $preorder = $this->createPreorder('courier');
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'invoice_sent'])->assertOk();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'fulfillment' => 'pickup',
            'courier_name' => null, // pickup tidak boleh membawa kurir (aturan 021)
            'items' => [['id' => $preorder['items'][0]['id'], 'variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('preorders', ['id' => $preorder['id'], 'dispatch_status' => 'invoice_sent']);
    }

    public function test_dates_follow_the_target_state_and_are_never_client_supplied(): void
    {
        $preorder = $this->createPreorder('courier');
        $url = "/api/v1/preorders/{$preorder['id']}/dispatch-status";

        $this->assertNull($preorder['invoice_sent_at'] ?? null);

        // Klien mencoba menyelundupkan tanggal sendiri — harus diabaikan.
        $sent = $this->patchJson($url, ['dispatch_status' => 'invoice_sent', 'invoice_sent_at' => '2000-01-01 00:00:00'])
            ->assertOk()->json();
        $this->assertNotNull($sent['invoice_sent_at']);
        $this->assertNull($sent['shipping_at']);
        $this->assertStringNotContainsString('2000-01-01', $sent['invoice_sent_at']);

        $shipping = $this->patchJson($url, ['dispatch_status' => 'shipping'])->assertOk()->json();
        $this->assertNotNull($shipping['shipping_at']);
        $this->assertSame($sent['invoice_sent_at'], $shipping['invoice_sent_at']); // tanggal invoice dipertahankan

        // Mundur dari shipping: tanggal pengiriman hilang, tanggal invoice tetap.
        $back = $this->patchJson($url, ['dispatch_status' => 'invoice_sent'])->assertOk()->json();
        $this->assertNull($back['shipping_at']);
        $this->assertSame($sent['invoice_sent_at'], $back['invoice_sent_at']);

        // Kembali ke pending: keduanya hilang.
        $pending = $this->patchJson($url, ['dispatch_status' => 'pending'])->assertOk()->json();
        $this->assertNull($pending['invoice_sent_at']);
        $this->assertNull($pending['shipping_at']);
    }

    public function test_remarking_the_active_value_does_not_move_its_date(): void
    {
        $preorder = $this->createPreorder('courier');
        $url = "/api/v1/preorders/{$preorder['id']}/dispatch-status";

        $first = $this->patchJson($url, ['dispatch_status' => 'invoice_sent'])->assertOk()->json('invoice_sent_at');
        $this->travel(2)->hours();
        $second = $this->patchJson($url, ['dispatch_status' => 'invoice_sent'])->assertOk()->json('invoice_sent_at');

        $this->assertSame($first, $second);
    }

    public function test_editing_a_shipping_preorder_to_pickup_clears_the_shipping_date(): void
    {
        $preorder = $this->createPreorder('courier');
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/dispatch-status", ['dispatch_status' => 'shipping'])->assertOk();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}", [
            'fulfillment' => 'pickup',
            'courier_name' => null,
            'items' => [['id' => $preorder['items'][0]['id'], 'variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('preorders', ['id' => $preorder['id'], 'dispatch_status' => 'invoice_sent', 'shipping_at' => null]);
    }

    public function test_list_and_detail_expose_created_updated_and_dispatch_dates_and_sort_by_them(): void
    {
        $older = $this->createPreorder();
        $this->travel(1)->hours();
        $newer = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$older['id']}/dispatch-status", ['dispatch_status' => 'invoice_sent'])->assertOk();

        $row = collect($this->getJson('/api/v1/preorders')->assertOk()->json('data'))->firstWhere('id', $older['id']);
        foreach (['created_at', 'updated_at', 'invoice_sent_at', 'shipping_at'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertNotNull($row['invoice_sent_at']);

        $detail = $this->getJson("/api/v1/preorders/{$older['id']}")->assertOk()->json();
        foreach (['created_at', 'updated_at', 'invoice_sent_at', 'shipping_at'] as $key) {
            $this->assertArrayHasKey($key, $detail);
        }

        $asc = collect($this->getJson('/api/v1/preorders?sort_by=created_at&sort_dir=asc')->json('data'))->pluck('id')->all();
        $this->assertSame([$older['id'], $newer['id']], $asc);
        // `older` baru saja di-update → paling baru menurut updated_at.
        $byUpdated = collect($this->getJson('/api/v1/preorders?sort_by=updated_at&sort_dir=desc')->json('data'))->pluck('id')->all();
        $this->assertSame($older['id'], $byUpdated[0]);
    }

    public function test_list_rows_expose_dispatch_status(): void
    {
        $preorder = $this->createPreorder();

        $this->getJson('/api/v1/preorders')
            ->assertOk()->assertJsonPath('data.0.id', $preorder['id'])
            ->assertJsonPath('data.0.dispatch_status', 'pending');
    }
}
