<?php

namespace Tests\Feature;

use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentChannelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // BUG YANG DITEMUKAN & DIPERBAIKI (Task 4) — index() sebelumnya
    // memanggil route('payment-channels.qr', ...) yang tidak pernah
    // didefinisikan, sehingga setiap channel BERGAMBAR membuat
    // GET /payment-channels melempar 500 ke SELURUH daftar, bukan cuma
    // channel itu. Regresi ini memastikan channel dengan qr_image tetap
    // bisa dimuat daftarnya.
    public function test_listing_channels_with_a_qr_image_does_not_500(): void
    {
        Storage::fake('public');
        $this->actingAsRole('cashier');

        PaymentChannel::factory()->create([
            'type' => 'qr_ewallet',
            'provider' => 'Gopay',
            'account_number' => null,
            'qr_image_path' => 'payment-channels/existing.jpg',
        ]);

        $response = $this->getJson('/api/v1/payment-channels');

        $response->assertOk();
        $this->assertNotNull($response->json('data.0.qr_image_url'));
        $this->assertStringContainsString('/storage/payment-channels/existing.jpg', $response->json('data.0.qr_image_url'));
    }

    public function test_bank_transfer_channel_without_image_degrades_gracefully(): void
    {
        Storage::fake('public');
        $this->actingAsRole('cashier');

        PaymentChannel::factory()->create([
            'type' => 'bank_transfer',
            'provider' => 'BCA',
            'account_number' => '1234567890',
            'qr_image_path' => null,
        ]);

        $response = $this->getJson('/api/v1/payment-channels')->assertOk();

        $this->assertNull($response->json('data.0.qr_image_url'));
    }

    public function test_owner_can_create_a_qr_channel_with_an_image(): void
    {
        Storage::fake('public');
        $this->actingAsRole('owner');

        $response = $this->post('/api/v1/payment-channels', [
            'type' => 'qr_ewallet',
            'provider' => 'Gopay',
            'account_name' => 'Toko Ryu',
            'qr_image' => UploadedFile::fake()->image('gopay.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('qr_image_url'));

        $channel = PaymentChannel::first();
        Storage::disk('public')->assertExists($channel->qr_image_path);
    }

    public function test_rejects_a_non_image_file_disguised_as_jpg(): void
    {
        Storage::fake('public');
        $this->actingAsRole('owner');

        $fakeImage = UploadedFile::fake()->create('fake.jpg', 10, 'application/pdf');

        $response = $this->post('/api/v1/payment-channels', [
            'type' => 'qr_ewallet',
            'provider' => 'Gopay',
            'account_name' => 'Toko Ryu',
            'qr_image' => $fakeImage,
        ]);

        $response->assertStatus(422);
    }

    public function test_cashier_cannot_create_a_channel(): void
    {
        $this->actingAsRole('cashier');

        $response = $this->postJson('/api/v1/payment-channels', [
            'type' => 'bank_transfer',
            'provider' => 'BCA',
            'account_name' => 'Toko Ryu',
            'account_number' => '123',
        ]);

        $response->assertStatus(403);
    }

    public function test_owner_can_add_an_image_to_an_existing_channel_via_update(): void
    {
        Storage::fake('public');
        $owner = $this->actingAsRole('owner');

        $channel = PaymentChannel::factory()->create([
            'type' => 'qr_ewallet',
            'provider' => 'Gopay',
            'account_number' => null,
            'qr_image_path' => null,
        ]);

        $response = $this->post("/api/v1/payment-channels/{$channel->id}", [
            'qr_image' => UploadedFile::fake()->image('gopay.png'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('qr_image_url'));
        $this->assertNotNull($channel->fresh()->qr_image_path);
    }

    public function test_update_can_remove_an_existing_image(): void
    {
        Storage::fake('public');
        $this->actingAsRole('owner');

        $path = UploadedFile::fake()->image('old.jpg')->store('payment-channels', 'public');
        $channel = PaymentChannel::factory()->create(['qr_image_path' => $path]);

        $response = $this->post("/api/v1/payment-channels/{$channel->id}", [
            'remove_qr_image' => true,
        ]);

        $response->assertOk();
        $this->assertNull($response->json('qr_image_url'));
        $this->assertNull($channel->fresh()->qr_image_path);
        Storage::disk('public')->assertMissing($path);
    }

    // 024-invoice-layout-shipping-slip (US6) — soft-delete kanal pembayaran.
    public function test_owner_can_delete_a_channel_without_payments(): void
    {
        $this->actingAsRole('owner');

        $channel = PaymentChannel::factory()->create([
            'type' => 'bank_transfer',
            'provider' => 'BCA',
            'account_number' => '1234567890',
        ]);

        $response = $this->deleteJson("/api/v1/payment-channels/{$channel->id}");

        $response->assertNoContent();
        $this->assertNotNull($channel->fresh()->deleted_at);
    }

    public function test_cashier_cannot_delete_a_channel(): void
    {
        $this->actingAsRole('cashier');

        $channel = PaymentChannel::factory()->create([
            'type' => 'bank_transfer',
            'provider' => 'BCA',
        ]);

        $response = $this->deleteJson("/api/v1/payment-channels/{$channel->id}");

        $response->assertForbidden();
        $this->assertNull($channel->fresh()->deleted_at);
    }

    public function test_cannot_delete_a_channel_that_has_payments(): void
    {
        $this->actingAsRole('owner');

        $channel = PaymentChannel::factory()->create([
            'type' => 'bank_transfer',
            'provider' => 'BCA',
        ]);

        // Buat preorder + payment yang merujuk channel ini — cukup channel_id
        // terpakai pada tabel payments agar delete diblokir (409). Tidak ada
        // PaymentFactory, jadi masukkan lewat DB::table() langsung.
        $user = User::factory()->create(['role' => 'cashier']);
        $event = \App\Models\Event::factory()->create();
        $customer = \App\Models\Customer::factory()->create();
        $preorder = \App\Models\Preorder::create([
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'preorder_number' => 'PO-TEST-001',
            'fulfillment' => 'pickup',
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'status' => 'handed_over',
        ]);

        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'order_id' => null,
            'preorder_id' => $preorder->id,
            'channel_id' => $channel->id,
            'method' => 'bank_transfer',
            'purpose' => 'full',
            'amount' => 100000,
            'verification' => 'verified',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/payment-channels/{$channel->id}");

        $response->assertConflict();
        $this->assertNull($channel->fresh()->deleted_at);
    }
}
