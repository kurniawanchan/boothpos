<?php

namespace Tests\Feature\SellerScope;

use App\Models\Customer;
use App\Models\PurchaseOrder;
use App\Models\SellerSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 058 US2 — pelanggan, purchase order dan settlement mengikuti seller pengguna. */
class CustomersSettlementsPoReadTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_customers_are_visible_through_visible_orders_or_their_origin_seller(): void
    {
        $w = $this->buildSellerWorld();
        $stranger = Customer::factory()->create(['name' => 'Tidak Terkait']);            // tanpa order
        $origin = Customer::factory()->create(['name' => 'Dibuat Seller A', 'artist_id' => $w['a']->id]);
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/customers?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        // Order hanya-A -> pelanggan A; order campuran -> pelanggan B (terlihat lewat order yang terlihat); asal seller A.
        $this->assertEqualsCanonicalizing([$w['customerA']->id, $w['customerB']->id, $origin->id], $ids);
        $this->assertNotContains($stranger->id, $ids);
        $this->getJson("/api/v1/customers/{$stranger->id}")->assertNotFound();
    }

    public function test_a_customer_created_by_a_scoped_user_is_stamped_and_stays_visible_to_them(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $id = $this->postJson('/api/v1/customers', ['name' => 'Baru Seller A'])->assertCreated()->json('id');
        $this->assertSame($w['a']->id, Customer::withoutGlobalScopes()->find($id)->artist_id);
        $this->getJson("/api/v1/customers/{$id}")->assertOk();
    }

    public function test_customer_history_lists_only_visible_orders(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        // Pelanggan B punya order hanya-B (tak terlihat) dan campuran (terlihat).
        $res = $this->getJson("/api/v1/customers/{$w['customerB']->id}/transactions")->assertOk()->getContent();
        $this->assertStringContainsString($w['orderMixed']->order_number, $res);
        $this->assertStringNotContainsString($w['orderB']->order_number, $res);
    }

    public function test_purchase_orders_follow_the_seller(): void
    {
        $w = $this->buildSellerWorld();
        $poA = PurchaseOrder::factory()->create(['artist_id' => $w['a']->id]);
        $poB = PurchaseOrder::factory()->create(['artist_id' => $w['b']->id]);
        $legacy = PurchaseOrder::factory()->legacy()->create();
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/purchase-orders?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$poA->id], $ids);
        $this->getJson("/api/v1/purchase-orders/{$poB->id}")->assertNotFound();
        $this->getJson("/api/v1/purchase-orders/{$legacy->id}")->assertNotFound();
    }

    public function test_settlements_follow_the_seller(): void
    {
        $w = $this->buildSellerWorld();
        $event = $w['session']->event_id;
        $mk = fn ($artist) => SellerSettlement::withoutGlobalScopes()->forceCreate([
            'artist_id' => $artist->id, 'event_id' => $event, 'status' => 'draft',
            'created_by' => $w['cashier']->id, 'event_name' => 'Event Uji', 'artist_name' => $artist->name, 'artist_code' => $artist->code, 'data_mode' => 'live',
        ]);
        $sA = $mk($w['a']);
        $sB = $mk($w['b']);
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/settlements?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$sA->id], $ids);
        $this->getJson("/api/v1/settlements/{$sB->id}")->assertNotFound();
        $this->getJson("/api/v1/settlements/preview?event_id={$event}&artist_id={$w['b']->id}")->assertNotFound();
    }
}
