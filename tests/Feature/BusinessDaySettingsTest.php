<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T006) — empat kunci pengaturan baru: validasi, default, dan keterbukaan.
 */
class BusinessDaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function saveSettings(array $rows)
    {
        return $this->putJson('/api/v1/settings', ['settings' => $rows]);
    }

    public function test_the_owner_can_save_valid_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->saveSettings([
            ['key' => 'store_timezone', 'value' => 'Asia/Makassar'],
            ['key' => 'business_day_cutoff', 'value' => '04:00'],
            ['key' => 'cash_difference_tolerance', 'value' => '7500'],
            ['key' => 'device_offline_after_minutes', 'value' => '10'],
        ])->assertOk();

        $this->getJson('/api/v1/settings/features')
            ->assertOk()
            ->assertJsonPath('store_timezone', 'Asia/Makassar')
            ->assertJsonPath('business_day_cutoff', '04:00');
    }

    public function test_features_exposes_the_defaults_to_any_role(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')
            ->assertOk()
            ->assertJsonPath('store_timezone', 'Asia/Jakarta')
            ->assertJsonPath('business_day_cutoff', '00:00');
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused(string $key, string $value): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->saveSettings([['key' => $key, 'value' => $value]])->assertStatus(422);
    }

    public static function invalidValues(): array
    {
        return [
            'unknown timezone' => ['store_timezone', 'Mars/Olympus'],
            'cutoff out of range' => ['business_day_cutoff', '24:00'],
            'cutoff not a time' => ['business_day_cutoff', 'pagi'],
            'negative tolerance' => ['cash_difference_tolerance', '-1'],
            'tolerance not a number' => ['cash_difference_tolerance', 'banyak'],
            'offline minutes zero' => ['device_offline_after_minutes', '0'],
            'offline minutes too large' => ['device_offline_after_minutes', '1441'],
            'offline minutes fractional' => ['device_offline_after_minutes', '2.5'],
        ];
    }

    public function test_a_cashier_cannot_change_them(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->saveSettings([['key' => 'store_timezone', 'value' => 'Asia/Makassar']])->assertForbidden();
    }
}
