<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\Product;
use App\Models\User;
use App\Services\PreorderService;
use App\Support\ReportSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 039-preorder-seller-subtotal — GET /reports/preorders?breakdown=artist ganti
 * `subtotals` (satu per penjual) dan ekspor Excel "Per Seller" memuat baris
 * Subtotal yang SAMA. Subtotal = jumlah baris yang ditampilkan (dalam sen);
 * outstanding = jumlah outstanding baris (yang sudah >= 0), bukan nilai - terkumpul.
 */
class PreorderSellerSubtotalsReportTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Customer $customer;

    private Category $category;

    private int $sku = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner']), 'sanctum');
        $this->event = Event::factory()->create(['status' => 'active']);
        $this->customer = Customer::factory()->create();
        $this->category = Category::factory()->create();
    }

    private function seller(string $name): array
    {
        $artist = Artist::factory()->create(['name' => $name]);
        $product = Product::factory()->create(['artist_id' => $artist->id, 'category_id' => $this->category->id]);
        $variant = $product->variants()->create(['sku' => 'SUB'.str_pad((string) ++$this->sku, 8, '0', STR_PAD_LEFT), 'sell_price' => 1000, 'cost_price' => 100, 'current_stock' => 100]);

        return [$artist, $variant];
    }

    /** Satu pre-order satu item, qty $qty x 1000, dibayar $paid lewat service (jalur nyata). */
    private function preorder($variant, int $qty, float $paid = 0, ?Event $event = null): Preorder
    {
        $service = app(PreorderService::class);
        $preorder = $service->create([
            'event_id' => ($event ?? $this->event)->id, 'customer_id' => $this->customer->id, 'fulfillment' => 'pickup',
            'items' => [['variant_id' => $variant->id, 'qty' => $qty]],
        ], User::factory()->create(['role' => 'owner']));

        if ($paid > 0) {
            $service->recordPayment($preorder, ['method' => 'cash', 'channel_id' => null, 'purpose' => 'down_payment', 'amount' => $paid]);
        }

        return $preorder->fresh();
    }

    private function report(?Event $event = null): array
    {
        return $this->getJson('/api/v1/reports/preorders?event_id='.($event ?? $this->event)->id.'&breakdown=artist')->assertOk()->json();
    }

    /** Fixture utama: 4 penjual — A (3 baris), B (1 baris), dua penjual bernama SAMA (C, D) yang bisa saling menyusup bila hanya diurutkan menurut nama. */
    private function seedMany(): array
    {
        [$a, $va] = $this->seller('Artist A');
        $this->preorder($va, 10);            // ordered / unpaid
        $this->preorder($va, 10, 4000);      // dp_paid / partial
        $this->preorder($va, 8, 8000);       // dp_paid / paid
        [$b, $vb] = $this->seller('Artist B');
        $this->preorder($vb, 5);
        [$c, $vc] = $this->seller('Same Name');
        [$d, $vd] = $this->seller('Same Name');
        $this->preorder($vc, 3);
        $this->preorder($vd, 2);
        $this->preorder($vc, 2, 500);
        $this->preorder($vd, 2, 500);

        return [$a, $b, $c, $d];
    }

    private function centsOf(array $rows, string $key): int
    {
        return array_sum(array_map(fn ($r) => ReportSplit::cents($r[$key]), $rows));
    }

    // ------------------------------------------------------------------ API

    public function test_the_response_adds_one_subtotal_per_seller_equal_to_the_sum_of_its_rows(): void
    {
        [$a, $b, $c, $d] = $this->seedMany();

        $json = $this->report();
        $rows = $json['rows'];
        $subtotals = collect($json['subtotals']);

        $this->assertCount(4, $subtotals);
        $this->assertGreaterThanOrEqual(3, collect($rows)->where('artist_id', $a->id)->count(), 'seller A has several rows');
        $this->assertSame(1, collect($rows)->where('artist_id', $b->id)->count(), 'seller B has a single row and still gets a subtotal');

        foreach ($subtotals as $sub) {
            $own = array_values(array_filter($rows, fn ($r) => $r['artist_id'] === $sub['artist_id']));
            $this->assertSame(array_sum(array_column($own, 'preorder_count')), $sub['preorder_count']);
            $this->assertSame(ReportSplit::money($this->centsOf($own, 'total_order_value')), $sub['total_order_value']);
            $this->assertSame(ReportSplit::money($this->centsOf($own, 'total_collected')), $sub['total_collected']);
            $this->assertSame(ReportSplit::money($this->centsOf($own, 'total_outstanding')), $sub['total_outstanding']);
            $this->assertSame(['artist_id', 'artist_name', 'preorder_count', 'total_order_value', 'total_collected', 'total_outstanding'], array_keys($sub));
        }
    }

    public function test_the_subtotals_add_up_to_the_sum_of_all_rows_which_is_the_grand_total(): void
    {
        $this->seedMany();
        $json = $this->report();

        foreach (['total_order_value', 'total_collected', 'total_outstanding'] as $field) {
            $this->assertSame($this->centsOf($json['rows'], $field), $this->centsOf($json['subtotals'], $field), $field);
        }
        $this->assertSame(array_sum(array_column($json['rows'], 'preorder_count')), array_sum(array_column($json['subtotals'], 'preorder_count')));
    }

    public function test_rows_are_contiguous_per_seller_even_when_two_sellers_share_a_name(): void
    {
        $this->seedMany();
        $ids = array_column($this->report()['rows'], 'artist_id');

        $seen = [];
        $previous = null;
        foreach ($ids as $id) {
            if ($id !== $previous) {
                $this->assertNotContains($id, $seen, "artist {$id} reappears after another seller's row");
                $seen[] = $id;
            }
            $previous = $id;
        }
        $this->assertCount(4, $seen);
    }

    public function test_subtotals_are_in_the_same_seller_order_as_the_rows(): void
    {
        $this->seedMany();
        $json = $this->report();

        $order = array_values(array_unique(array_column($json['rows'], 'artist_id')));
        $this->assertSame($order, array_column($json['subtotals'], 'artist_id'));
    }

    public function test_an_overpaid_row_is_summed_as_displayed_not_as_value_minus_collected(): void
    {
        [$e, $ve] = $this->seller('Artist Over');
        $pre = $this->preorder($ve, 10);
        // Pembayaran lama (sebelum dibatasi): langsung ke tabel, melebihi total 10000.
        Payment::create(['preorder_id' => $pre->id, 'method' => 'cash', 'purpose' => 'down_payment', 'amount' => 15000, 'verification' => 'verified', 'paid_at' => now()]);

        $json = $this->report();
        $rows = array_values(array_filter($json['rows'], fn ($r) => $r['artist_id'] === $e->id));
        $sub = collect($json['subtotals'])->firstWhere('artist_id', $e->id);

        $this->assertNotEmpty($rows);
        $this->assertSame(ReportSplit::money($this->centsOf($rows, 'total_outstanding')), $sub['total_outstanding']);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row['total_outstanding'], 'row outstanding is never negative');
        }
    }

    public function test_the_event_filter_scopes_the_subtotals_like_the_rows(): void
    {
        $this->seedMany();
        $other = Event::factory()->create(['status' => 'active']);
        [$z, $vz] = $this->seller('Artist Elsewhere');
        $this->preorder($vz, 7, 0, $other);

        $mine = $this->report();
        $theirs = $this->report($other);

        $this->assertNotContains($z->id, array_column($mine['subtotals'], 'artist_id'));
        $this->assertSame([$z->id], array_column($theirs['subtotals'], 'artist_id'));
        $this->assertSame('7000.00', $theirs['subtotals'][0]['total_order_value']);
    }

    public function test_an_event_without_preorders_has_no_rows_and_no_subtotals_and_the_default_response_is_unchanged(): void
    {
        $empty = Event::factory()->create(['status' => 'active']);

        $json = $this->report($empty);
        $this->assertSame([], $json['rows']);
        $this->assertSame([], $json['subtotals']);

        $default = $this->getJson('/api/v1/reports/preorders?event_id='.$empty->id)->assertOk()->json();
        $this->assertArrayNotHasKey('subtotals', $default);
    }

    // --------------------------------------------------------------- export

    private function perSellerSheet(?Event $event = null): array
    {
        $response = $this->get('/api/v1/reports/preorder/export?event_id='.($event ?? $this->event)->id)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'laporan-preorder').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        return [
            'names' => $book->getSheetNames(),
            'summary' => $book->getSheetByName('Ringkasan')->toArray(),
            'sellers' => $book->getSheetByName('Per Seller')->toArray(),
        ];
    }

    public function test_the_per_seller_sheet_has_a_labelled_subtotal_row_after_each_sellers_rows_matching_the_screen(): void
    {
        $this->seedMany();
        $json = $this->report();
        $sheet = $this->perSellerSheet();

        $this->assertSame(['Ringkasan', 'Per Seller'], $sheet['names']);
        $this->assertSame(['artist_id', 'artist_name', 'status', 'payment_completeness', 'preorder_count', 'total_order_value', 'total_collected', 'total_outstanding'], $sheet['sellers'][0]);

        $body = array_slice($sheet['sellers'], 1);
        $subtotalRows = array_values(array_filter($body, fn ($r) => str_starts_with((string) $r[1], 'Subtotal — ')));
        $this->assertCount(4, $subtotalRows);
        $this->assertCount(count($json['rows']) + 4, $body, 'only subtotal rows were added; no grand-total row');

        foreach ($json['subtotals'] as $i => $sub) {
            $row = $subtotalRows[$i];
            $this->assertSame('Subtotal — '.$sub['artist_name'], $row[1]);
            $this->assertEmpty($row[0], 'artist_id is empty on a subtotal row');
            $this->assertEmpty($row[2]);
            $this->assertEmpty($row[3]);
            $this->assertEquals($sub['preorder_count'], $row[4]);
            $this->assertEqualsWithDelta((float) $sub['total_order_value'], (float) $row[5], 0.001);
            $this->assertEqualsWithDelta((float) $sub['total_collected'], (float) $row[6], 0.001);
            $this->assertEqualsWithDelta((float) $sub['total_outstanding'], (float) $row[7], 0.001);
        }
    }

    public function test_each_subtotal_row_directly_follows_the_last_row_of_its_seller(): void
    {
        $this->seedMany();
        $body = array_slice($this->perSellerSheet()['sellers'], 1);

        $currentSeller = null;
        foreach ($body as $i => $row) {
            $isSubtotal = str_starts_with((string) $row[1], 'Subtotal — ');
            if (! $isSubtotal) {
                $currentSeller = $row[0];

                continue;
            }
            $this->assertNotNull($currentSeller);
            $next = $body[$i + 1] ?? null;
            $this->assertTrue($next === null || $next[0] !== $currentSeller, 'a subtotal follows the LAST row of its seller');
        }
    }

    public function test_the_summary_sheet_is_unchanged_and_an_empty_event_exports_only_the_header(): void
    {
        $this->seedMany();
        $summaryJson = $this->getJson('/api/v1/reports/preorders?event_id='.$this->event->id)->assertOk()->json('rows');
        $sheet = $this->perSellerSheet();

        $this->assertCount(count($summaryJson) + 1, array_filter($sheet['summary'], fn ($r) => array_filter($r, fn ($c) => $c !== null && $c !== '')));
        $this->assertSame([], array_values(array_filter(array_map(fn ($r) => (string) ($r[0] ?? ''), $sheet['summary']), fn ($v) => str_starts_with($v, 'Subtotal'))));

        $empty = $this->perSellerSheet(Event::factory()->create(['status' => 'active']));
        $this->assertCount(1, array_filter($empty['sellers'], fn ($r) => array_filter($r, fn ($c) => $c !== null && $c !== '')));
    }
}
