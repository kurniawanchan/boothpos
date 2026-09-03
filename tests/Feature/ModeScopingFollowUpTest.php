<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ModeGate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SakanaFridgeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 003-seed-demo-live follow-up (2026-09-03) — nama toko per mode, daftar
 * user per mode (tanpa memutus sesi login), dan pencarian transaksi
 * penjualan (nomor/customer/artist) + nama artist di struk.
 */
class ModeScopingFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_seeding_demo_data_does_not_overwrite_the_live_store_name(): void
    {
        $this->seed(DatabaseSeeder::class);
        Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Toko Asli', 'type' => 'string', 'group' => 'receipt']);

        $this->seed(SakanaFridgeDemoSeeder::class);

        $this->assertSame('Toko Asli', Setting::get('store_name'));
        $this->assertSame('Demo Sakana Fridge', Setting::get('store_name_demo'));
    }

    public function test_settings_view_saves_store_name_under_the_active_modes_key(): void
    {
        $this->actingAsOwner();

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'store_name_demo', 'value' => 'Toko Demo', 'type' => 'string', 'group' => 'receipt']],
        ])->assertOk();

        $this->assertSame('Toko Demo', Setting::get('store_name_demo'));
        $this->assertNull(Setting::get('store_name'));
    }

    public function test_a_new_user_created_while_demo_is_active_does_not_appear_in_the_live_users_list(): void
    {
        $owner = $this->actingAsOwner();

        $demoUser = ModeGate::runAs('demo', fn () => User::factory()->create(['role' => 'cashier']));
        $this->assertSame('demo', $demoUser->data_mode);

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'live', 'type' => 'string', 'group' => 'system']);
        $response = $this->getJson('/api/v1/users');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('username');
        $this->assertNotContains($demoUser->username, $names);
        $this->assertContains($owner->username, $names);
    }

    public function test_switching_mode_never_invalidates_an_active_session(): void
    {
        $owner = $this->actingAsOwner();

        Setting::updateOrCreate(['key' => 'system_mode'], ['value' => 'demo', 'type' => 'string', 'group' => 'system']);
        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('system_mode', 'demo');

        // Masih memakai token/sesi yang SAMA — tidak pernah 401 gara-gara mode berpindah.
        $me = $this->getJson('/api/v1/auth/me');
        $me->assertOk();
        $this->assertSame($owner->username, $me->json('username'));
    }

    public function test_sales_transactions_include_artist_names_for_search_and_receipt(): void
    {
        $owner = $this->actingAsOwner();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sell_price' => 20000]);
        app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 5);
        $session = CashierSession::factory()->create(['user_id' => $owner->id]);

        $order = app(OrderService::class)->create([
            'session_id' => $session->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ], $owner);

        $artistName = $variant->product->artist->name;

        $sales = $this->getJson('/api/v1/reports/sales')->assertOk();
        $transaction = collect($sales->json('transactions'))->firstWhere('id', $order->id);
        $this->assertNotNull($transaction);
        $this->assertContains($artistName, $transaction['artist_names']);

        $receipt = $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk();
        $this->assertSame($artistName, $receipt->json('items.0.artist_name'));
    }

    public function test_demo_seeder_creates_demo_users_linked_to_existing_shared_roles(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SakanaFridgeDemoSeeder::class);

        $kasirDemo = User::withoutGlobalScope(\App\Models\Concerns\DataModeScope::class)
            ->where('username', 'kasir_demo')->first();
        $adminDemo = User::withoutGlobalScope(\App\Models\Concerns\DataModeScope::class)
            ->where('username', 'admin_demo')->first();

        $this->assertNotNull($kasirDemo);
        $this->assertSame('demo', $kasirDemo->data_mode);
        $this->assertSame('Kasir', $kasirDemo->role->name);

        $this->assertNotNull($adminDemo);
        $this->assertSame('demo', $adminDemo->data_mode);
        $this->assertSame('Admin', $adminDemo->role->name);
    }

    public function test_receipt_shows_the_orders_customer_not_the_store_contact_person(): void
    {
        Setting::updateOrCreate(['key' => 'store_contact_person'], ['value' => 'Budi Santoso', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_contact_phone'], ['value' => '0812-3456-7890', 'type' => 'string', 'group' => 'receipt']);
        Setting::updateOrCreate(['key' => 'store_contact_email'], ['value' => 'toko@contoh.com', 'type' => 'string', 'group' => 'receipt']);

        $owner = $this->actingAsOwner();
        $customer = Customer::factory()->create(['name' => 'Citra Maheswari', 'phone' => '0899999999', 'email' => 'citra@contoh.com']);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sell_price' => 15000]);
        app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 5);
        $session = CashierSession::factory()->create(['user_id' => $owner->id]);

        $order = app(OrderService::class)->create([
            'session_id' => $session->id,
            'customer_id' => $customer->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ], $owner);

        $receipt = $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk();
        $this->assertSame('Citra Maheswari', $receipt->json('customer_name'));
        $this->assertSame('0899999999', $receipt->json('customer_phone'));
        $this->assertNotSame('Budi Santoso', $receipt->json('customer_name'));
    }

    public function test_receipt_shows_no_customer_block_for_a_walkin_order(): void
    {
        $owner = $this->actingAsOwner();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sell_price' => 15000]);
        app(\App\Services\StockService::class)->applyMovement($variant, 'initial', 5);
        $session = CashierSession::factory()->create(['user_id' => $owner->id]);

        $order = app(OrderService::class)->create([
            'session_id' => $session->id,
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 15000]],
        ], $owner);

        $receipt = $this->getJson("/api/v1/orders/{$order->id}/receipt")->assertOk();
        $this->assertNull($receipt->json('customer_name'));
    }
}
