<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 048 / T030 — angka tampilan terminal POS terbaca semua peran, tanpa data sensitif. */
class SettingsFeaturesPosTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_reads_the_pos_numbers(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertOk()
            ->assertJsonPath('tax_rate', 10)->assertJsonPath('tax_label', 'Pajak')
            ->assertJsonPath('variance_tolerance', 5000)->assertJsonPath('cash_out_approval_threshold', 200000);
    }

    public function test_defaults_are_zero_tax_and_the_design_thresholds(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');

        $this->getJson('/api/v1/settings/features')->assertJsonPath('tax_rate', 0);
    }
}
