<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Modul ini sebelumnya punya controller tanpa test — celah yang baru
// ketahuan saat audit akhir sesi. Ditambahkan sekarang, bukan didiamkan.
class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_customer(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/customers', ['name' => 'Budi Santoso', 'phone' => '081234567890']);

        $response->assertCreated()->assertJsonPath('name', 'Budi Santoso');
    }

    public function test_creating_customer_requires_name(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/customers', ['phone' => '08123'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_guest_cannot_access_customers(): void
    {
        $this->getJson('/api/v1/customers')->assertStatus(401);
    }

    public function test_search_finds_customer_by_phone(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');
        Customer::factory()->create(['name' => 'Ani', 'phone' => '081111111111']);
        Customer::factory()->create(['name' => 'Budi', 'phone' => '082222222222']);

        $response = $this->getJson('/api/v1/customers?search=081111');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ani', $response->json('data.0.name'));
    }
}
