<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Support\PosSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OutletFixtures;
use Tests\TestCase;

/**
 * 083 — pengaturan pajak: tarif, label, dan tampil/sembunyi (`tax_show`). Yang dijaga di sini: validasinya, peran yang
 * boleh mengubah, jejak aktivitas, dan terutama bahwa `tax_show` HANYA tampilan — pajak tetap ditagih dan tersimpan
 * saat disembunyikan, dan order lama tidak pernah berubah ketika tarif diganti.
 */
class TaxSettingTest extends TestCase
{
    use OutletFixtures, RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** @param  list<array{key: string, value: mixed, type: string}>  $rows */
    private function putSettings(array $rows)
    {
        return $this->putJson('/api/v1/settings', ['settings' => array_map(fn ($r) => $r + ['group' => 'pos'], $rows)]);
    }

    private function saveTax(string $rate, string $label, bool $show): void
    {
        $this->putSettings([
            ['key' => 'tax_rate', 'value' => $rate, 'type' => 'decimal'],
            ['key' => 'tax_label', 'value' => $label, 'type' => 'string'],
            ['key' => 'tax_show', 'value' => $show, 'type' => 'boolean'],
        ])->assertOk();
    }

    /** Order tunai sebesar harga + pajak (pembayaran berlebih ditolak, kurang membuat order belum lunas). */
    private function paidOrder(int $price, int $paid): int
    {
        $outlet = Outlet::query()->firstOrFail();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['home_outlet_id' => $outlet->id])->id,
            'sell_price' => $price,
        ]);
        $cashier = User::factory()->create(['role' => 'cashier']);

        return $this->orderAt($this->shiftAt($outlet, $cashier), $variant, 1, ['payments' => [['method' => 'cash', 'amount' => $paid]]])->id;
    }

    public function test_defaults_show_the_tax_line_so_existing_installs_are_unchanged(): void
    {
        $this->assertTrue(PosSettings::taxShow());

        $this->actingAsRole('cashier');
        $this->getJson('/api/v1/settings/features')->assertOk()
            ->assertJsonPath('tax_show', true)->assertJsonPath('tax_rate', 0)->assertJsonPath('tax_label', 'Pajak');
    }

    public function test_the_owner_saves_rate_label_and_toggle_and_features_reflects_them(): void
    {
        $this->actingAsRole('owner');
        $this->saveTax('10', 'PPN', false);

        $this->assertFalse(PosSettings::taxShow());
        $this->getJson('/api/v1/settings/features')->assertOk()
            ->assertJsonPath('tax_rate', 10)->assertJsonPath('tax_label', 'PPN')->assertJsonPath('tax_show', false);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_refused_and_the_old_value_stays(string $key, string $type, mixed $bad): void
    {
        $this->actingAsRole('owner');
        $this->saveTax('10', 'PPN', true);

        $this->putSettings([['key' => $key, 'value' => $bad, 'type' => $type]])->assertStatus(422);

        $this->assertSame(10.0, PosSettings::taxRate());
        $this->assertSame('PPN', PosSettings::taxLabel());
        $this->assertTrue(PosSettings::taxShow());
    }

    public static function invalidValues(): array
    {
        return [
            'rate above 100' => ['tax_rate', 'decimal', '101'],
            'negative rate' => ['tax_rate', 'decimal', '-1'],
            'non numeric rate' => ['tax_rate', 'decimal', 'abc'],
            'three decimals' => ['tax_rate', 'decimal', '10.123'],
            'label of 31 characters' => ['tax_label', 'string', str_repeat('a', 31)],
            'toggle that is not a boolean' => ['tax_show', 'boolean', 'mungkin'],
        ];
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->actingAsRole('owner');

        $this->saveTax('0', 'Pajak', true);
        $this->saveTax('100', str_repeat('a', 30), true);
        $this->saveTax('11.5', 'PPN', false);

        $this->assertSame(11.5, PosSettings::taxRate());
    }

    public function test_a_cashier_cannot_change_the_tax_setting(): void
    {
        $this->actingAsRole('cashier');

        $this->putSettings([['key' => 'tax_rate', 'value' => '10', 'type' => 'decimal']])->assertStatus(403);
        $this->assertSame(0.0, PosSettings::taxRate());
    }

    public function test_every_change_is_logged_with_old_and_new_values(): void
    {
        $owner = $this->actingAsRole('owner');
        $this->saveTax('10', 'PPN', true);

        $this->putSettings([['key' => 'tax_show', 'value' => false, 'type' => 'boolean']])->assertOk();

        $setting = Setting::where('key', 'tax_show')->firstOrFail();
        $log = ActivityLog::where('entity_type', 'Setting')->where('entity_id', $setting->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        // Boolean disimpan sebagai '1'/'0' oleh normalizeValueForStorage; yang dijaga: nilai lama aktif, baru mati.
        $this->assertTrue(filter_var($log->old_values['value'], FILTER_VALIDATE_BOOLEAN));
        $this->assertFalse(filter_var($log->new_values['value'], FILTER_VALIDATE_BOOLEAN));
    }

    /**
     * Catatan bertanggal 2026-10-31 (perintah pemilik, membalik 083): pajak yang ditampilkan mati TIDAK dihitung.
     * Dulu tes ini memastikan kebalikannya (`a hidden tax is still charged and stored`).
     */
    public function test_a_hidden_tax_is_not_charged(): void
    {
        $this->actingAsRole('owner');
        $this->makeOutlets(1);
        $this->saveTax('10', 'PPN', false);

        $orderId = $this->paidOrder(7000, 7000);

        $this->getJson("/api/v1/orders/{$orderId}")->assertOk()
            ->assertJsonPath('tax_amount', '0.00')->assertJsonPath('tax_rate', '0.00')->assertJsonPath('total_amount', '7000.00');
        $this->getJson("/api/v1/orders/{$orderId}/receipt")->assertOk()
            ->assertJsonPath('tax_show', false)->assertJsonPath('tax_amount', '0.00')->assertJsonPath('total_amount', '7000.00');
    }

    public function test_switching_tax_display_off_affects_only_new_orders(): void
    {
        $this->actingAsRole('owner');
        $this->makeOutlets(1);
        $this->saveTax('10', 'PPN', true);
        $before = $this->paidOrder(7000, 7700);

        $this->saveTax('10', 'PPN', false);
        $after = $this->paidOrder(7000, 7000);

        $this->getJson("/api/v1/orders/{$before}")->assertJsonPath('tax_amount', '700.00')->assertJsonPath('total_amount', '7700.00');
        $this->getJson("/api/v1/orders/{$after}")->assertJsonPath('tax_amount', '0.00')->assertJsonPath('total_amount', '7000.00');
    }

    public function test_the_receipt_follows_the_toggle_but_never_the_total(): void
    {
        $this->actingAsRole('owner');
        $this->makeOutlets(1);
        $this->saveTax('10', 'PPN', true);
        $orderId = $this->paidOrder(7000, 7700);

        $shown = $this->getJson("/api/v1/orders/{$orderId}/receipt")->assertOk();
        $this->saveTax('10', 'PPN', false);
        $hidden = $this->getJson("/api/v1/orders/{$orderId}/receipt")->assertOk();

        $this->assertTrue($shown->json('tax_show'));
        $this->assertFalse($hidden->json('tax_show'));
        $this->assertSame($shown->json('total_amount'), $hidden->json('total_amount'));
        $this->assertSame($shown->json('tax_amount'), $hidden->json('tax_amount'));
    }

    public function test_changing_the_rate_never_rewrites_an_existing_order(): void
    {
        $this->actingAsRole('owner');
        $this->makeOutlets(1);
        $this->saveTax('10', 'PPN', true);
        $old = $this->paidOrder(7000, 7700);

        $this->saveTax('11', 'PPN', true);
        $new = $this->paidOrder(7000, 7770);

        $this->getJson("/api/v1/orders/{$old}/receipt")->assertOk()
            ->assertJsonPath('tax_rate', '10.00')->assertJsonPath('tax_amount', '700.00')->assertJsonPath('total_amount', '7700.00');
        $this->getJson("/api/v1/orders/{$new}/receipt")->assertOk()
            ->assertJsonPath('tax_rate', '11.00')->assertJsonPath('tax_amount', '770.00')->assertJsonPath('total_amount', '7770.00');
    }

    public function test_an_order_made_without_tax_stays_without_tax_when_a_rate_is_set_later(): void
    {
        $this->actingAsRole('owner');
        $this->makeOutlets(1);
        $old = $this->paidOrder(7000, 7000);

        $this->saveTax('10', 'PPN', true);

        $this->getJson("/api/v1/orders/{$old}/receipt")->assertOk()
            ->assertJsonPath('tax_amount', '0.00')->assertJsonPath('total_amount', '7000.00');
    }
}
