<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nama aplikasi ("BoothPOS") yang bisa diubah owner/admin lewat baris
 * settings `app_name` — dipakai sidebar dan teks "Powered by …" di dokumen.
 * Bukan nama TOKO (`store_name`): itu identitas penjual, ini merek produknya.
 */
class AppNameTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function setAppName(?string $value)
    {
        return $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'app_name', 'value' => $value, 'type' => 'string', 'group' => 'general']],
        ]);
    }

    public function test_features_endpoint_defaults_the_app_name_to_boothpos(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('app_name', 'BoothPOS');
    }

    public function test_owner_can_rename_the_app_and_every_role_sees_the_new_name(): void
    {
        $this->actingAsRole('owner');
        $this->setAppName('  Kasir Sakana  ')->assertOk();

        $this->actingAsRole('cashier');
        // Spasi di tepi dibuang, supaya tidak tampil sebagai "Powered by   X".
        $this->getJson('/api/v1/settings/features')->assertJsonPath('app_name', 'Kasir Sakana');
    }

    public function test_blank_app_name_falls_back_to_the_default(): void
    {
        $this->actingAsRole('owner');

        $this->setAppName('')->assertOk();
        $this->getJson('/api/v1/settings/features')->assertJsonPath('app_name', 'BoothPOS');

        $this->setAppName('   ')->assertOk();
        $this->getJson('/api/v1/settings/features')->assertJsonPath('app_name', 'BoothPOS');

        $this->setAppName(null)->assertOk();
        $this->getJson('/api/v1/settings/features')->assertJsonPath('app_name', 'BoothPOS');
    }

    public function test_an_app_name_longer_than_50_characters_is_rejected(): void
    {
        $this->actingAsRole('owner');

        $this->setAppName(str_repeat('a', 51))->assertStatus(422);
        $this->setAppName(str_repeat('a', 50))->assertOk();
    }

    public function test_a_role_without_settings_access_cannot_rename_the_app(): void
    {
        $this->actingAsRole('cashier');

        $this->setAppName('Diretas')->assertStatus(403);
        $this->getJson('/api/v1/settings/features')->assertJsonPath('app_name', 'BoothPOS');
    }

    public function test_the_preorder_invoice_payload_carries_the_app_name(): void
    {
        $this->actingAsRole('owner');
        $product = Product::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'is_preorder' => true,
        ]);
        $variant = $product->variants()->create(['sku' => 'APPN0001', 'sell_price' => 10000, 'cost_price' => 5000, 'current_stock' => 0]);
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => Customer::factory()->create()->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ])->assertCreated()->json();

        $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice")->assertOk()->assertJsonPath('app_name', 'BoothPOS');

        $this->setAppName('Kasir Sakana')->assertOk();
        $this->getJson("/api/v1/preorders/{$preorder['id']}/invoice")->assertJsonPath('app_name', 'Kasir Sakana');

        // Unduh massal memakai jalur payload yang sama.
        $bulk = $this->postJson('/api/v1/preorders/bulk-invoices', ['preorder_ids' => [$preorder['id']], 'document' => 'invoice'])->assertOk();
        $this->assertSame('Kasir Sakana', $bulk->json('data.0.app_name'));
    }
}
