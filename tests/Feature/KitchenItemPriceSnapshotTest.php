<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\KitchenTicketItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 042-dashboard-revamp (T011) — harga satuan disalin ke item tiket saat dikirim, supaya "Void setelah
 * fire" punya nilai yang tidak ikut berubah bila harga varian diubah kemudian.
 */
class KitchenItemPriceSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    public function test_the_line_value_per_unit_is_copied_when_the_ticket_is_sent_and_survives_a_price_change(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner, 'sanctum');
        $session = $this->makeShift($owner);
        $variant = $this->makeVariant(stock: 20, price: 15000);
        $tab = $this->apiOpenTab($session->id, DiningTable::factory()->create()->id)->assertCreated()->json('id');
        $this->apiAddLine($tab, $variant->id, 2)->assertCreated();

        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        $variant->update(['sell_price' => 99000]);

        $item = KitchenTicketItem::firstOrFail();
        $this->assertSame('15000.00', (string) $item->unit_price);
        $this->assertSame(2, $item->qty);
    }
}
