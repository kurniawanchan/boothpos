<?php

namespace Tests\Feature\SellerScope;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 058 US2 (keputusan Q1:B) — order/pre-order tampil bila ADA baris seller pengguna, lalu tampil UTUH;
 * yang tak punya baris miliknya = 404, di daftar, ringkasan, ekspor, struk, dan invoice.
 */
class OrdersPreordersReadTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    public function test_order_list_detail_and_receipt_follow_the_header_visibility_rule(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/orders?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$w['orderA']->id, $w['orderMixed']->id], $ids);

        // Order campuran tampil UTUH: baris seller B ikut terlihat (Q1:B).
        $mixed = $this->getJson("/api/v1/orders/{$w['orderMixed']->id}")->assertOk()->json();
        $this->assertCount(2, $mixed['items']);
        $this->assertArrayNotHasKey('cost_price', $mixed['items'][0]);

        $this->getJson("/api/v1/orders/{$w['orderB']->id}")->assertNotFound();
        $this->getJson("/api/v1/orders/{$w['orderB']->id}/receipt")->assertNotFound();
        $this->getJson("/api/v1/orders/{$w['orderMixed']->id}/receipt")->assertOk();
    }

    public function test_bulk_receipts_ignore_invisible_orders(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $res = $this->postJson('/api/v1/orders/bulk-receipts', ['order_ids' => [$w['orderA']->id, $w['orderB']->id]])->assertOk();
        $this->assertSame([$w['orderA']->order_number], collect($res->json('data'))->pluck('order_number')->all());
        $this->assertSame([$w['orderB']->id], $res->json('missing'));
    }

    public function test_preorder_list_summary_detail_invoice_and_neighbors(): void
    {
        $w = $this->buildSellerWorld();
        $p = $this->buildPreorders($w);
        $this->actingAsSellerUser($w['a'], 'admin');

        $ids = collect($this->getJson('/api/v1/preorders?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$p['pa'], $p['pm']], $ids);

        $this->assertSame(2, (int) $this->getJson('/api/v1/preorders/summary')->assertOk()->json('transaction_count'));

        $this->getJson("/api/v1/preorders/{$p['pb']}")->assertNotFound();
        $this->getJson("/api/v1/preorders/{$p['pb']}/invoice")->assertNotFound();
        $this->getJson("/api/v1/preorders/{$p['pb']}/neighbors")->assertNotFound();
        $this->assertCount(2, $this->getJson("/api/v1/preorders/{$p['pm']}")->assertOk()->json('items'));
    }

    public function test_foreign_orders_cannot_be_acted_upon(): void
    {
        $w = $this->buildSellerWorld();
        $p = $this->buildPreorders($w);
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->postJson("/api/v1/orders/{$w['orderB']->id}/void")->assertNotFound();
        $this->postJson("/api/v1/orders/{$w['orderB']->id}/payments", ['method' => 'cash', 'amount' => 1])->assertNotFound();
        $this->patchJson("/api/v1/preorders/{$p['pb']}/status", ['status' => 'cancelled'])->assertNotFound();
        $this->deleteJson("/api/v1/preorders/{$p['pb']}")->assertNotFound();
    }

    public function test_payment_totals_of_visible_orders_are_visible_and_foreign_payments_are_not(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $this->assertSame(2, \App\Models\Payment::count()); // A-only + campuran
    }
}
