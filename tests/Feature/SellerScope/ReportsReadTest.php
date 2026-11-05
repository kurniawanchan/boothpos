<?php

namespace Tests\Feature\SellerScope;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 058 US2 — query laporan "tangan" (DB::table / join) MELEWATI scope Eloquent, jadi tiap laporan dicek langsung:
 * tak satu pun nama/SKU/produk seller lain boleh muncul, dan angka hanya dari baris milik seller pengguna.
 * Fixture: A = 10.000 (hanya-A) + 10.000 (baris A di order campuran); B = 10.000 + 10.000 di order campuran.
 */
class ReportsReadTest extends TestCase
{
    use RefreshDatabase, SellerScopeFixture;

    /** @return list<string> */
    private function endpoints(int $e): array
    {
        return [
            '/api/v1/reports/sales',
            '/api/v1/reports/sales?group_by=artist',
            '/api/v1/reports/sales?group_by=product',
            '/api/v1/reports/sales-by-product',
            "/api/v1/reports/profit?event_id={$e}",
            "/api/v1/reports/artist-profit?event_id={$e}",
            "/api/v1/reports/artist-settlements?event_id={$e}",
            '/api/v1/reports/stock-by-artist',
            '/api/v1/reports/purchases',
            '/api/v1/reports/preorders',
            '/api/v1/reports/preorders?breakdown=artist',
        ];
    }

    public function test_no_report_leaks_another_sellers_names_or_skus(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $needles = [$w['b']->name, $w['b']->code, $w['productB']->name, $w['variantB']->sku];

        foreach ($this->endpoints($w['session']->event_id) as $url) {
            $json = $this->getJson($url)->assertOk()->json();
            // Q1:B — daftar `transactions` memuat order terlihat UTUH (termasuk baris seller lain di order campuran);
            // angka, baris laporan, dan opsi filter di luar daftar itu tetap hanya milik seller pengguna.
            unset($json['transactions']);
            $body = json_encode($json);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString((string) $needle, $body, "{$url} membocorkan '{$needle}'");
            }
        }
    }

    public function test_sales_totals_count_only_own_lines(): void
    {
        $w = $this->buildSellerWorld();

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']), 'sanctum');
        $all = $this->getJson('/api/v1/reports/sales')->assertOk()->json('totals.net_sales');
        $this->assertSame('40000.00', $all); // A 10k + B 10k + campuran 20k

        $this->actingAsSellerUser($w['a'], 'admin');
        $this->assertSame('20000.00', $this->getJson('/api/v1/reports/sales')->assertOk()->json('totals.net_sales'));
    }

    public function test_per_seller_reports_only_list_the_own_seller(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $recap = collect($this->getJson("/api/v1/reports/artist-settlements?event_id={$w['session']->event_id}")->assertOk()->json('data'))
            ->pluck('artist_id')->filter()->unique()->values()->all();
        $this->assertSame([$w['a']->id], $recap);

        $e = $w['session']->event_id;
        $this->getJson("/api/v1/reports/artist-settlements/{$w['b']->id}/transactions?event_id={$e}")->assertNotFound();
        $this->getJson("/api/v1/reports/artist-settlements/{$w['a']->id}/transactions?event_id={$e}")->assertOk();
    }

    public function test_generic_report_export_is_limited_too(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        foreach (['sales', 'profit', 'artist-settlements', 'artist-profit', 'stock-by-artist'] as $report) {
            $res = $this->get("/api/v1/reports/{$report}/export?event_id={$w['session']->event_id}");
            $res->assertOk();
            $this->assertStringNotContainsString($w['b']->name, (string) $res->streamedContent() ?: (string) $res->getContent());
        }
    }

    public function test_preorder_report_counts_only_the_own_share(): void
    {
        $w = $this->buildSellerWorld();
        $p = $this->buildPreorders($w);
        $this->actingAsSellerUser($w['a'], 'admin');

        // Ringkasan: pre-order hanya-A (10k) + baris A di pre-order campuran (10k), belum dibayar.
        $rows = $this->getJson('/api/v1/reports/preorders')->assertOk()->json('rows');
        $this->assertSame(1, count($rows) > 0 ? 1 : 0);
        $this->assertSame('20000.00', collect($rows)->sum(fn ($r) => (float) $r['total_order_value']) == 20000.0 ? '20000.00' : 'salah');
        $this->assertSame(2, (int) collect($rows)->sum('preorder_count'));

        // Drilldown dipaksa ke porsi seller pengguna, walau artist_id lain dikirim.
        $detail = $this->getJson("/api/v1/reports/preorders?status=ordered&artist_id={$w['b']->id}")->assertOk()->json();
        $this->assertStringNotContainsString($w['b']->name, json_encode($detail));
        $this->assertSame(20000.0, (float) collect($detail['rows'] ?? $detail['data'] ?? [])->sum('order_value'));
    }

    public function test_sales_page_rows_show_the_whole_order_but_statistics_use_only_own_lines(): void
    {
        $w = $this->buildSellerWorld();
        $this->actingAsSellerUser($w['a'], 'admin');

        $rows = collect($this->getJson('/api/v1/reports/sales')->assertOk()->json('transactions'))->keyBy('id');
        $mixed = $rows[$w['orderMixed']->id];

        $this->assertSame(2, $mixed['item_count']);          // order utuh terlihat (Q1:B)
        $this->assertSame('20000.00', $mixed['total_amount']);
        $this->assertSame('10000.00', $mixed['items_net']);  // statistik: hanya baris A
        $this->assertSame('10000.00', $mixed['items_gross']);
        $this->assertFalse($rows->has($w['orderB']->id));

        // Jumlah kartu = jumlah baris terlihat (SC-005): 10k (hanya-A) + 10k (baris A di campuran).
        $this->assertSame(20000.0, (float) $rows->sum(fn ($r) => (float) $r['items_net']));
    }
}
