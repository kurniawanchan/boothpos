<?php

namespace Tests\Feature;

use App\Models\KitchenTicket;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Support\ModeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RestaurantFixtures;
use Tests\Support\WjmCatalogFixtures;
use Tests\TestCase;

/**
 * 069 (research D5, FR-008, SC-006) — kategori dipakai bersama dua outlet, tetapi tiket item outlet kedua harus
 * masuk ke stasiun outlet kedua. Kategori bersama menunjuk stasiun outlet pertama; hanya `products.station_id`
 * yang membuat tiket tidak salah alamat tanpa mengubah kategori outlet pertama.
 */
class WjmCatalogKitchenRoutingTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;
    use WjmCatalogFixtures;

    public function test_an_item_seeded_for_the_second_outlet_sends_its_ticket_to_that_outlets_station(): void
    {
        Storage::fake('public');
        $this->wjmRun(['live']);
        [$a, $b] = ModeGate::runAs('live', fn () => [
            Outlet::where('code', 'WJ3')->firstOrFail(),
            Outlet::factory()->create(['code' => 'WJD', 'name' => 'Warkop Uji 2']),
        ]);
        $this->wjmRun(['live'], path: $this->wjmCatalogPath(null, 'wjm2-catalog'));

        $stationB = ModeGate::runAs('live', fn () => Station::withoutGlobalScopes()->where('outlet_id', $b->id)->where('name', 'Bar')->firstOrFail());
        $stationA = ModeGate::runAs('live', fn () => Station::withoutGlobalScopes()->where('outlet_id', $a->id)->where('name', 'Bar')->firstOrFail());
        $variant = ModeGate::runAs('live', fn () => Product::where('home_outlet_id', $b->id)->where('name', 'Jus Uji')->firstOrFail()->variants()->orderBy('id')->firstOrFail());
        $this->assertSame($stationA->id, $variant->product->category->station_id, 'prasyarat: kategori bersama menunjuk stasiun outlet pertama');

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum');
        $shift = $this->makeShift($cashier, ['outlet_id' => $b->id]);
        $tab = $this->apiOpenTab($shift->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $variant->id)->assertSuccessful();

        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();

        $ticket = KitchenTicket::firstOrFail();
        $this->assertSame($stationB->id, $ticket->station_id);
        $this->assertNotSame($stationA->id, $ticket->station_id);
    }
}
