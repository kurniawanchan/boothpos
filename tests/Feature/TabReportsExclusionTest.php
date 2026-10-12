<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\User;
use App\Exports\GenericArrayExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Support\RestaurantFixtures;
use Tests\TestCase;

/**
 * 028 FR-042 — tab yang belum dibayar TIDAK PERNAH masuk ke penjualan, laba,
 * settlement penjual, ringkasan shift, ataupun halaman Sales; setelah dibayar
 * ia masuk tepat satu kali, seperti penjualan biasa.
 */
class TabReportsExclusionTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantFixtures;

    private User $cashier;
    private User $owner;
    private \App\Models\CashierSession $session;
    private int $tab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($this->cashier, 'sanctum');
        $this->session = $this->makeShift($this->cashier);
        $this->tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        // 2 x 25.000, uang muka tunai 20.000 — uang sungguhan ada di laci, tapi bukan penjualan.
        $this->apiAddLine($this->tab, $this->makeVariant(stock: 20, price: 25000)->id, 2)->assertCreated();
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 20000]])->assertOk()->assertJsonPath('status', 'open');
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner, 'sanctum');
    }

    public function test_an_unpaid_tab_is_in_no_sales_report(): void
    {
        $this->asOwner();
        $event = $this->session->event_id;

        $sales = $this->getJson("/api/v1/reports/sales?event_id={$event}")->assertOk()->json();
        $this->assertEquals(0, $sales['totals']['unit_count']);
        $this->assertEquals(0, $sales['totals']['gross_sales']);
        $this->assertEquals(0, $sales['totals']['net_sales']);
        $this->assertSame([], $sales['transactions'] ?? []);

        $byProduct = $this->getJson("/api/v1/reports/sales?event_id={$event}&group_by=product")->assertOk()->json();
        $this->assertSame([], $byProduct['data'] ?? []);
    }

    public function test_an_unpaid_tab_is_in_no_profit_or_seller_report(): void
    {
        $this->asOwner();
        $event = $this->session->event_id;

        $profit = $this->getJson("/api/v1/reports/profit?event_id={$event}")->assertOk()->json();
        $this->assertEquals(0, $profit['revenue'] ?? $profit['totals']['revenue'] ?? 0);

        $settlements = $this->getJson("/api/v1/reports/outlet-settlements?event_id={$event}")->assertOk()->json('data');
        foreach ($settlements as $row) {
            $this->assertEquals(0, $row['total_sales'], 'settlement penjual menghitung tab yang belum dibayar');
        }

        $outletProfit = $this->getJson("/api/v1/reports/outlet-profit?event_id={$event}")->assertOk()->json();
        $this->assertSame([], $outletProfit['data'] ?? []);
    }

    public function test_an_unpaid_tab_is_not_in_the_shift_summary_or_customer_history_or_order_list(): void
    {
        $summary = $this->getJson("/api/v1/sessions/{$this->session->id}/summary")->assertOk()->json();
        $this->assertSame(0, $summary['order_count']);
        $this->assertEquals(0, $summary['total_sales']);

        $this->assertFalse(collect($this->getJson('/api/v1/orders')->json('data'))->pluck('id')->contains($this->tab));
    }

    public function test_once_paid_the_tab_counts_exactly_once_like_any_sale(): void
    {
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 30000]])->assertOk()->assertJsonPath('status', 'completed');

        $this->asOwner();
        $event = $this->session->event_id;

        // Bukti bahwa kunci yang dibaca test "tidak ada di laporan" memang kunci yang
        // benar: kunci-kunci yang SAMA harus terisi begitu tab lunas.
        $profit = $this->getJson("/api/v1/reports/profit?event_id={$event}")->assertOk()->json();
        $this->assertEquals(50000, $profit['revenue'] ?? $profit['totals']['revenue'] ?? null);
        $settlement = collect($this->getJson("/api/v1/reports/outlet-settlements?event_id={$event}")->json('data'))->firstWhere('total_sales', '!=', 0);
        $this->assertEquals(50000, $settlement['total_sales'] ?? null);

        $sales = $this->getJson("/api/v1/reports/sales?event_id={$event}")->assertOk()->json();
        $this->assertEquals(2, $sales['totals']['unit_count']);
        $this->assertEquals(50000, $sales['totals']['gross_sales']);
        $this->assertCount(1, $sales['transactions']);
        $this->assertSame($this->tab, $sales['transactions'][0]['id']);

        $this->actingAs($this->cashier, 'sanctum');
        $summary = $this->getJson("/api/v1/sessions/{$this->session->id}/summary")->assertOk()->json();
        $this->assertSame(1, $summary['order_count']);
        $this->assertEquals(50000, $summary['total_sales']);
    }

    /** Jalankan ekspor Sales dan kembalikan baris yang DITULIS ke berkas. */
    private function exportedRows(array $body = []): array
    {
        Excel::fake();
        $this->postJson('/api/v1/reports/sales/transactions/export', array_merge(['event_id' => $this->session->event_id], $body))->assertOk();

        $rows = [];
        Excel::assertDownloaded('transaksi-penjualan.xlsx', function (GenericArrayExport $export) use (&$rows) {
            $rows = $export->array();

            return true;
        });

        return $rows;
    }

    public function test_the_sales_page_export_never_includes_an_open_tab(): void
    {
        $this->asOwner();

        // Seluruh event, dan juga dengan KUNCI tab itu dikirim klien: server membangun
        // ulang baris dari filternya sendiri dan mengabaikan kunci di luar hasilnya.
        $this->assertCount(0, $this->exportedRows());
        $this->assertCount(0, $this->exportedRows(['keys' => ["order:{$this->tab}"]]));

        // Setelah lunas ia ikut — tepat satu baris.
        $this->actingAs($this->cashier, 'sanctum');
        $this->apiPay($this->tab, [['method' => 'cash', 'amount' => 30000]])->assertOk();
        $this->asOwner();
        $this->assertCount(1, $this->exportedRows());
    }

    // --- semua keadaan dapur & susun-ulang (T155) -------------------------------------

    private function assertNoSalesFigure(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $event = $this->session->event_id;

        $sales = $this->getJson("/api/v1/reports/sales?event_id={$event}")->assertOk()->json();
        $this->assertEquals(0, $sales['totals']['unit_count']);
        $this->assertEquals(0, $sales['totals']['gross_sales']);
        $this->assertSame([], $sales['transactions'] ?? []);
        foreach ($this->getJson("/api/v1/reports/outlet-settlements?event_id={$event}")->json('data') as $row) {
            $this->assertEquals(0, $row['total_sales']);
        }
        $this->assertCount(0, $this->exportedRows());

        $this->actingAs($this->cashier, 'sanctum');
    }

    public function test_an_open_tab_in_every_kitchen_state_stays_out_of_every_figure(): void
    {
        $station = $this->makeStation('Dapur');
        $this->apiAddLine($this->tab, $this->makeMenuItem(categoryStation: $station, price: 7000)->id, 3)->assertCreated();
        $this->postJson("/api/v1/tabs/{$this->tab}/send")->assertOk();

        foreach (['new', 'preparing', 'ready', 'served'] as $status) {
            \App\Models\KitchenTicketItem::query()->update(['status' => $status]);
            $this->assertNoSalesFigure();
        }
    }

    public function test_a_merged_away_and_a_voided_tab_are_in_no_figure(): void
    {
        $other = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $this->apiAddLine($other, $this->makeVariant(price: 9000)->id)->assertCreated();
        $fresh = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $this->apiAddLine($fresh, $this->makeVariant(price: 4000)->id)->assertCreated();

        // $fresh digabung ke $other lalu lenyap; $other kemudian dibatalkan oleh owner.
        $this->postJson("/api/v1/tabs/{$other}/merge", ['source_tab_id' => $fresh])->assertOk();
        $this->assertSame('voided', \App\Models\Order::find($fresh)->status);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/tabs/{$other}/void", ['reason' => 'tamu pergi'])->assertOk();

        // Tab dari setUp punya uang muka tunai (bukan penjualan): tidak bisa dibatalkan, tidak masuk angka apa pun.
        $this->postJson("/api/v1/tabs/{$this->tab}/void", ['reason' => 'x'])->assertStatus(409);
        $this->assertNoSalesFigure();
    }

    public function test_cancelled_items_never_count_and_only_what_is_left_is_sold(): void
    {
        $station = $this->makeStation('Dapur');
        $tab = $this->apiOpenTab($this->session->id, DiningTable::factory()->create()->id)->json('id');
        $keep = $this->makeMenuItem(categoryStation: $station, price: 10000);
        $drop = $this->makeMenuItem(categoryStation: $station, price: 50000);
        $this->apiAddLine($tab, $keep->id)->assertCreated();
        $dropLine = max($this->apiAddLine($tab, $drop->id)->json('lines.*.id'));
        $this->postJson("/api/v1/tabs/{$tab}/send")->assertOk();
        $this->deleteJson("/api/v1/tabs/{$tab}/items/{$dropLine}", ['reason' => 'salah pesan'])->assertOk();

        $this->apiPay($tab, [['method' => 'cash', 'amount' => 10000]])->assertOk()->assertJsonPath('status', 'completed');

        // Tab pertama (setUp) masih terbuka dengan uang muka; hanya tab ini yang dijual: 1 unit, 10.000.
        $this->actingAs($this->owner, 'sanctum');
        $sales = $this->getJson("/api/v1/reports/sales?event_id={$this->session->event_id}")->assertOk()->json();
        $this->assertEquals(1, $sales['totals']['unit_count']);
        $this->assertEquals(10000, $sales['totals']['gross_sales']);
        $this->assertCount(1, $sales['transactions']);
    }
}
