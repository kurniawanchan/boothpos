<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MenuSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 043-products-page-redesign (T016) — pengaturan menu: default aman, validasi, dan keterbukaan flag rilis.
 */
class MenuSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $rows)
    {
        return $this->putJson('/api/v1/settings', ['settings' => $rows]);
    }

    public function test_defaults_keep_existing_installs_unchanged(): void
    {
        $this->assertFalse(MenuSettings::releaseEnabled());
        $this->assertSame(15.0, MenuSettings::approvalThresholdPct());
        $this->assertSame('500', MenuSettings::roundingDefault());
    }

    public function test_the_owner_can_enable_releases_and_every_role_can_read_the_flag(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->save([
            ['key' => 'menu_release_enabled', 'value' => 'true'],
            ['key' => 'price_approval_threshold_pct', 'value' => '20'],
            ['key' => 'price_rounding_default', 'value' => 'none'],
        ])->assertOk();

        $this->assertTrue(MenuSettings::releaseEnabled());
        $this->assertSame(20.0, MenuSettings::approvalThresholdPct());
        $this->assertSame('none', MenuSettings::roundingDefault());

        $this->actingAs(User::factory()->create(['role' => 'cashier']), 'sanctum');
        $this->getJson('/api/v1/settings/features')->assertOk()->assertJsonPath('menu_release_enabled', true);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("badValues")]
    public function test_invalid_values_are_refused(string $key, string $value): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');

        $this->save([['key' => $key, 'value' => $value]])->assertStatus(422);
    }

    public static function badValues(): array
    {
        return [
            'release flag not boolean' => ['menu_release_enabled', 'mungkin'],
            'threshold negative' => ['price_approval_threshold_pct', '-1'],
            'threshold over 100' => ['price_approval_threshold_pct', '101'],
            'threshold not a number' => ['price_approval_threshold_pct', 'banyak'],
            'rounding unknown' => ['price_rounding_default', '1000'],
        ];
    }
}
