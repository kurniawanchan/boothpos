<?php

namespace Tests\Feature\OrderApproval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 064 — jumlah query pratinjau tidak tumbuh per penjualan (Constitution V). Satu panggilan pemanasan dulu. */
class BulkQueryCountTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function countQueries(callable $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $fn();

        return $n;
    }

    public function test_preview_query_count_does_not_grow_with_the_number_of_sales(): void
    {
        $w = $this->buildApprovalWorld();
        $orders = $this->makeSalesOfA($w, 25);
        $ids = array_map(fn ($o) => $o->id, $orders);
        $this->actingAs($w['userA'], 'sanctum');

        $this->bulkPreview(array_slice($ids, 0, 2))->assertOk(); // pemanasan (cache pengaturan/lisensi)

        $few = $this->countQueries(fn () => $this->bulkPreview(array_slice($ids, 0, 5))->assertOk());
        $many = $this->countQueries(fn () => $this->bulkPreview($ids)->assertOk());

        $this->assertSame($few, $many, "pratinjau 5 penjualan = {$few} query, 25 penjualan = {$many} query");
    }
}
