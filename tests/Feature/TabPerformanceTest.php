<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 — jaga performa (Constitution V): jumlah query tidak boleh tumbuh dengan jumlah baris, dan
 * tab besar (±100 baris) tetap cepat dibayar/dipisah.
 */
class TabPerformanceTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($this->cashier, 'sanctum');
    }

    /** Tab dengan $n baris ber-stasiun, dibuat langsung ke DB supaya setup test tetap cepat. */
    private function bigTab(int $n): int
    {
        $session = $this->makeShift($this->cashier);
        $tab = $this->makeOpenOrder($session, $this->cashier, ['table_id' => DiningTable::factory()->create()->id, 'service_type' => 'dine_in']);
        // Nama stasiun unik per mode: setiap tab besar memakai stasiunnya sendiri.
        $station = $this->makeStation('Dapur '.uniqid());
        $variant = $this->makeMenuItem(categoryStation: $station, stock: 100000, price: 1000);

        for ($i = 0; $i < $n; $i++) {
            $this->addLineTo($tab, $variant, 1);
        }
        app(\App\Services\TabService::class)->recalculate($tab);

        return $tab->id;
    }

    private function queryCount(callable $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_reading_a_tab_runs_the_same_number_of_queries_for_5_and_100_lines(): void
    {
        $small = $this->bigTab(5);
        $large = $this->bigTab(100);
        $this->getJson("/api/v1/tabs/{$small}")->assertOk(); // pemanasan (selisih konstan pada panggilan pertama)

        $a = $this->queryCount(fn () => $this->getJson("/api/v1/tabs/{$small}")->assertOk());
        $b = $this->queryCount(fn () => $this->getJson("/api/v1/tabs/{$large}")->assertOk());

        $this->assertSame($a, $b, "GET /tabs/{id} menjalankan query per baris (N+1): 5 baris = $a query, 100 baris = $b query");
    }

    public function test_a_100_line_tab_is_settled_in_well_under_a_second(): void
    {
        $tab = $this->bigTab(100);

        $started = microtime(true);
        $res = $this->apiPay($tab, [['method' => 'cash', 'amount' => 100000]]);
        $elapsed = microtime(true) - $started;

        $res->assertOk()->assertJsonPath('status', 'completed');
        $this->assertLessThan(1.0, $elapsed, sprintf('Membayar tab 100 baris memakan %.2f dtk (target < 1 dtk)', $elapsed));
    }

    public function test_settling_runs_the_same_number_of_queries_regardless_of_the_number_of_lines(): void
    {
        $warmup = $this->bigTab(2);
        $small = $this->bigTab(5);
        $large = $this->bigTab(100);
        // Panggilan pertama selalu SATU query lebih banyak (konstan, tak bergantung jumlah baris): buang dulu.
        $this->apiPay($warmup, [['method' => 'cash', 'amount' => 2000]])->assertOk();

        $a = $this->queryCount(fn () => $this->apiPay($small, [['method' => 'cash', 'amount' => 5000]])->assertOk());
        $b = $this->queryCount(fn () => $this->apiPay($large, [['method' => 'cash', 'amount' => 100000]])->assertOk());

        $this->assertSame($a, $b, "Pembayaran tab menjalankan query per baris: 5 baris = $a, 100 baris = $b");
    }

    public function test_sending_a_100_line_tab_runs_a_bounded_number_of_queries_and_creates_one_ticket(): void
    {
        $tab = $this->bigTab(100);

        $queries = $this->queryCount(fn () => $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk());

        // Satu tiket dibuat; INSERT item tiket memang satu per baris (100), sisanya konstan kecil.
        $this->assertSame(1, \App\Models\KitchenTicket::count());
        $this->assertSame(100, \App\Models\KitchenTicketItem::count());
        $this->assertSame(100, OrderItem::whereNotNull('sent_at')->count());
        $this->assertLessThan(260, $queries, "Kirim tab 100 baris menjalankan $queries query");
    }

    public function test_the_kitchen_feed_runs_a_constant_number_of_queries_as_tickets_grow(): void
    {
        $station = $this->makeStation('Dapur');
        $this->makeTicketItem([], $station);
        $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertOk(); // pemanasan
        $few = $this->queryCount(fn () => $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertOk());

        for ($i = 0; $i < 25; $i++) {
            $this->makeTicketItem([], $station);
        }
        $many = $this->queryCount(fn () => $this->getJson("/api/v1/stations/{$station->id}/tickets")->assertOk());

        $this->assertSame($few, $many, "Umpan dapur menjalankan query per tiket: 1 tiket = $few, 26 tiket = $many");
    }
}
