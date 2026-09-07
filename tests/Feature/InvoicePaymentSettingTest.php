<?php

namespace Tests\Feature;

use App\Models\InvoicePaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentSettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_get_returns_blank_singleton_on_first_access(): void
    {
        $this->actingAsRole('owner');

        $response = $this->getJson('/api/v1/settings/payment');

        $response->assertOk();
        $response->assertJson([
            'bank_name' => null,
            'account_number' => null,
            'account_holder' => null,
            'instructions' => null,
        ]);
        $this->assertSame(1, InvoicePaymentSetting::count());
    }

    public function test_put_updates_the_singleton(): void
    {
        $this->actingAsRole('owner');

        $response = $this->putJson('/api/v1/settings/payment', [
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'PT Mekari Sejahtera',
            'instructions' => 'Transfer lalu konfirmasi ke admin.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('invoice_payment_settings', [
            'id' => 1,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'PT Mekari Sejahtera',
        ]);
    }

    public function test_repeated_updates_never_create_a_second_row(): void
    {
        $this->actingAsRole('owner');

        $this->putJson('/api/v1/settings/payment', ['bank_name' => 'BCA'])->assertOk();
        $this->putJson('/api/v1/settings/payment', ['bank_name' => 'Mandiri'])->assertOk();
        $this->putJson('/api/v1/settings/payment', ['bank_name' => 'BNI'])->assertOk();

        $this->assertSame(1, InvoicePaymentSetting::count());
        $this->assertDatabaseHas('invoice_payment_settings', ['id' => 1, 'bank_name' => 'BNI']);
    }

    public function test_cashier_without_settings_access_cannot_view_payment_settings(): void
    {
        $this->actingAsRole('cashier');

        $this->getJson('/api/v1/settings/payment')->assertStatus(403);
    }

    public function test_cashier_without_settings_access_cannot_update_payment_settings(): void
    {
        $this->actingAsRole('cashier');

        $this->putJson('/api/v1/settings/payment', ['bank_name' => 'Hacked'])->assertStatus(403);
        $this->assertDatabaseMissing('invoice_payment_settings', ['bank_name' => 'Hacked']);
    }
}
