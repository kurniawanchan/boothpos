<?php

namespace Tests\Feature\OrderApproval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Constitution V: jumlah kueri GET persetujuan tidak boleh tumbuh bersama jumlah seller/baris. */
class QueryCountTest extends TestCase
{
    use OrderApprovalFixture, RefreshDatabase;

    private function queries(int $orderId): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/v1/orders/{$orderId}/approvals")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_does_not_grow_with_the_number_of_sellers(): void
    {
        $w = $this->buildApprovalWorld();
        $this->actingAs($w['userA'], 'sanctum');
        $this->queries($w['orderA']->id); // pemanasan (cache setting/lisensi terisi pada panggilan pertama)

        $single = $this->queries($w['orderA']->id);
        $multi = $this->queries($w['orderMulti']->id);

        $this->assertSame($single, $multi, "1 seller={$single} kueri, 3 seller={$multi} kueri");
    }
}
