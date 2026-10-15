<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CashierSession;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 048 / T043 — karakterisasi: `summary()` dipindah ke ShiftReportService tanpa mengubah angka lama.
 * Skenario tetap: modal Rp 100.000, satu penjualan uang pas, satu penjualan dibayar Rp 150.000 (kembalian
 * Rp 50.000). Kas seharusnya = 100.000 + (100.000 + 150.000) − 50.000 = 300.000.
 */
class ShiftReportParityTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private ProductVariant $variant;

    private CashierSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');

        $product = Product::factory()->create([
            'home_outlet_id' => Outlet::factory()->create()->id, 'category_id' => Category::factory()->create()->id,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'PRTY0001', 'sell_price' => 100000, 'cost_price' => 40000, 'current_stock' => 100,
        ]);

        $this->shift = CashierSession::factory()->create([
            'user_id' => $this->cashier->id, 'status' => 'open', 'opening_cash' => 100000,
        ]);
        foreach ([100000, 150000] as $tendered) {
            $this->postJson('/api/v1/orders', [
                'session_id' => $this->shift->id, 'local_ref' => (string) Str::uuid(),
                'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
                'payments' => [['method' => 'cash', 'amount' => $tendered]],
            ])->assertCreated();
        }
    }

    public function test_the_summary_keeps_every_existing_key_and_value(): void
    {
        $res = $this->getJson("/api/v1/sessions/{$this->shift->id}/summary")->assertOk();

        // 048 — `expected_cash` tidak ada di ringkasan kasir (blind count); bentuk lama + angka kas masuk/keluar saja.
        $res->assertJsonStructure(['session', 'order_count', 'total_sales', 'by_method', 'opening_cash_entries', 'cash_in', 'cash_out', 'change_given'])
            ->assertJsonMissingPath('expected_cash')
            ->assertJsonPath('order_count', 2)
            ->assertJsonPath('by_method.0.method', 'cash')
            ->assertJsonPath('by_method.0.count', 2);

        $this->assertEqualsWithDelta(200000, (float) $res->json('total_sales'), 0.001);
        $this->assertEqualsWithDelta(250000, (float) $res->json('by_method.0.amount'), 0.001, 'jumlah yang diserahkan, bukan dikurangi kembalian');
    }

    public function test_the_legacy_close_agrees_with_the_summary_expectation(): void
    {
        // Penutupan lama (satu angka) harus menghasilkan selisih nol bila hitungannya 300.000.
        $this->postJson("/api/v1/sessions/{$this->shift->id}/close", ['closing_cash' => 300000])
            ->assertOk()->assertJsonPath('expected_cash', '300000.00')->assertJsonPath('cash_difference', '0.00');
    }

    public function test_a_closed_shift_keeps_its_stored_expected_cash(): void
    {
        $this->postJson("/api/v1/sessions/{$this->shift->id}/close", ['closing_cash' => 300000])->assertOk();

        // Angka modal diubah setelah tutup: rekonsiliasi yang tersimpan tidak boleh ikut bergeser.
        CashierSession::whereKey($this->shift->id)->update(['opening_cash' => 999999]);

        // Kas seharusnya untuk shift tertutup tetap yang tersimpan (ditampilkan di Z-report, bukan ringkasan kasir).
        $this->assertEqualsWithDelta(300000, (float) $this->shift->fresh()->expected_cash, 0.001);
    }
}
