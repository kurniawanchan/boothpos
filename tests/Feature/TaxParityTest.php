<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 085 / US1 — paritas pajak. Kasus tarif non-10 (100, 5, 2,5) dulu tidak pernah diuji: semua tes pajak lama memakai 10%,
 * jadi tarif yang macet di 10 tidak akan tertangkap. Kasus rumus dibaca dari fixture yang SAMA dengan tes Vitest dan Android
 * (`qa-tests/fixtures/tax-parity-cases.json`), supaya tiga platform tidak bisa menyimpang diam-diam.
 */
class TaxParityTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    public static function formulaCases(): array
    {
        $json = json_decode(file_get_contents(dirname(__DIR__, 2).'/qa-tests/fixtures/tax-parity-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($json['cases'] as $c) {
            $out["dasar {$c['base']} tarif {$c['rate']}"] = [(float) $c['base'], (float) $c['rate'], (float) $c['tax']];
        }

        return $out;
    }

    #[DataProvider('formulaCases')]
    public function test_the_formula_matches_the_shared_fixture(float $base, float $rate, float $tax): void
    {
        $this->assertSame($tax, TaxCalculator::compute($base, $rate)['tax']);
        $this->assertSame($rate, TaxCalculator::compute($base, $rate)['rate']);
    }

    private function saveRateAsOwner(string $rate): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->putJson('/api/v1/settings', ['settings' => [
            ['key' => 'tax_rate', 'value' => $rate, 'type' => 'decimal', 'group' => 'pos'],
        ]])->assertOk();
    }

    private User $cashier;

    private int $sessionId;

    /** @return array{0: User, 1: int} kasir + id tab kosong (shift dibuat sekali per tes) */
    private function cashierTab(): array
    {
        if (! isset($this->cashier)) {
            $this->cashier = User::factory()->create(['role' => 'cashier']);
            $this->sessionId = $this->makeShift($this->cashier)->id;
        }
        $this->actingAs($this->cashier, 'sanctum');

        return [$this->cashier, $this->apiOpenTab($this->sessionId)->json('id')];
    }

    public static function rates(): array
    {
        return [
            'seratus persen' => ['100', '60000.00', '120000.00'],
            'lima persen' => ['5', '3000.00', '63000.00'],
            'dua koma lima persen' => ['2.5', '1500.00', '61500.00'],
        ];
    }

    #[DataProvider('rates')]
    public function test_a_rate_saved_through_settings_is_the_rate_the_order_is_charged_with(string $rate, string $tax, string $total): void
    {
        $this->saveRateAsOwner($rate);
        [, $tab] = $this->cashierTab();
        $v = $this->makeVariant(stock: 10, price: 20_000);

        $this->apiAddLine($tab, $v->id, 3)->assertCreated()
            ->assertJsonPath('tax_amount', $tax)->assertJsonPath('tax_rate', number_format((float) $rate, 2, '.', ''))
            ->assertJsonPath('total_amount', $total);

        // Tab → pembayaran → struk: angka yang sama di setiap tempat yang membacanya.
        $this->apiPay($tab, [['method' => 'cash', 'amount' => (float) $total]])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('tax_amount', $tax)->assertJsonPath('total_amount', $total);
        $this->getJson("/api/v1/orders/{$tab}")->assertOk()
            ->assertJsonPath('tax_amount', $tax)->assertJsonPath('total_amount', $total);
        $this->getJson("/api/v1/orders/{$tab}/receipt")->assertOk()
            ->assertJsonPath('tax_amount', $tax)->assertJsonPath('total_amount', $total)
            ->assertJsonPath('tax_rate', number_format((float) $rate, 2, '.', ''));
    }

    public function test_the_order_discount_is_taken_off_before_the_tax_at_a_non_ten_rate(): void
    {
        $this->saveRateAsOwner('5');
        [, $tab] = $this->cashierTab();
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $this->apiAddLine($tab, $v->id, 3);

        // 60.000 − 10.000 = 50.000 → 5% = 2.500 → 52.500
        $this->apiPay($tab, [['method' => 'cash', 'amount' => 52_500]], null, ['discount_amount' => 10_000])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('tax_amount', '2500.00')->assertJsonPath('total_amount', '52500.00');
    }

    public function test_changing_the_rate_applies_to_the_next_order_and_never_to_a_paid_one(): void
    {
        $this->saveRateAsOwner('100');
        [, $first] = $this->cashierTab();
        $v = $this->makeVariant(stock: 10, price: 20_000);
        $this->apiAddLine($first, $v->id, 1);
        $this->apiPay($first, [['method' => 'cash', 'amount' => 40_000]])->assertJsonPath('status', 'completed');

        $this->saveRateAsOwner('5');

        [, $second] = $this->cashierTab();
        $this->apiAddLine($second, $v->id, 1)->assertCreated()->assertJsonPath('tax_amount', '1000.00');
        $this->getJson("/api/v1/orders/{$first}")->assertOk()
            ->assertJsonPath('tax_rate', '100.00')->assertJsonPath('tax_amount', '20000.00')->assertJsonPath('total_amount', '40000.00');
    }

    public function test_a_rate_changed_by_another_process_is_not_hidden_by_a_stale_cache(): void
    {
        // Menguji H3 (research): nilai lama tertahan di cache setelah baris settings berubah di luar jalur model.
        $this->saveRateAsOwner('10');
        $this->assertSame(10.0, \App\Support\PosSettings::taxRate());

        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '100', 'type' => 'decimal']);

        $this->assertSame(100.0, \App\Support\PosSettings::taxRate());
    }
}
