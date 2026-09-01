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
}
