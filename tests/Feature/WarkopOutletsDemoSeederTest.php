<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\VariantOutletStock;
use App\Support\ModeGate;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SakanaFridgeDemoSeeder;
use Database\Seeders\WarkopOutletsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 045 Stage 8 (T103) — demo multi-outlet opsional: bentuk dan idempotensi; seeder dasar tetap satu-outlet-bawaan.
 */
class WarkopOutletsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_three_outlets_assignments_tables_balances_and_a_transfer_in_transit_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SakanaFridgeDemoSeeder::class);

        $this->seed(WarkopOutletsDemoSeeder::class);
        $this->seed(WarkopOutletsDemoSeeder::class); // dijalankan ulang: tidak menggandakan

        ModeGate::runAs('demo', function () {
            $wj = Outlet::whereIn('code', ['WJ1', 'WJ2', 'WJ3'])->orderBy('code')->get();
            $this->assertSame(['Warkop WJM 1', 'Warkop WJM 2', 'Warkop WJM 3'], $wj->pluck('name')->all());
            $this->assertSame(9, DiningTable::withoutGlobalScopes()->whereIn('outlet_id', $wj->pluck('id'))->count());

            $kasir01 = User::withoutGlobalScopes()->where('username', 'kasir01')->first();
            $this->assertContains($wj[0]->id, $kasir01->outlets()->pluck('outlets.id')->all());

            $first = \App\Models\ProductVariant::orderBy('id')->first();
            $balance = fn ($o) => (int) VariantOutletStock::where(['variant_id' => $first->id, 'outlet_id' => $o->id])->value('current_stock');
            $this->assertSame(24 - 6, $balance($wj[0]), 'WJ1 gave 6 to the transfer in transit');
            $this->assertSame(24, $balance($wj[1]), 'the goods are not at WJ2 until received');

            $transfers = StockTransfer::withoutGlobalScopes()->get();
            $this->assertCount(1, $transfers);
            $this->assertSame('in_transit', $transfers[0]->status);
        });
    }

    public function test_the_base_demo_seeder_stays_a_single_default_outlet_setup(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(SakanaFridgeDemoSeeder::class);

        $this->assertSame(0, StockTransfer::withoutGlobalScopes()->count());
        $this->assertSame(0, Outlet::withoutGlobalScopes()->whereIn('code', ['WJ1', 'WJ2', 'WJ3'])->count());
    }
}
