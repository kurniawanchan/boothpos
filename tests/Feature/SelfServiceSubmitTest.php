<?php

namespace Tests\Feature;

use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\PaymentChannel;
use App\Models\SelfServiceOrder;
use App\Models\Setting;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\SelfServiceFixtures;
use Tests\TestCase;

/**
 * 090 / US1 — kirim pesanan mandiri: harga dari server, tanpa diskon, idempoten, ditahan (tanpa order/stok/tiket).
 */
class SelfServiceSubmitTest extends TestCase
{
    use RefreshDatabase;
    use SelfServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssSetUp();
    }

    /** Hanya tes pembatas yang membiarkan hitungan laju berjalan; tes lain mengirim banyak permintaan beruntun. */
    private bool $keepThrottle = false;

    private function submit(array $payload)
    {
        if (! $this->keepThrottle) {
            \Illuminate\Support\Facades\Cache::flush(); // mengosongkan hitungan pembatas (cache array) antar kiriman
        }

        return $this->postJson($this->ssUrl('/orders'), $payload);
    }

    public function test_submitting_holds_the_order_and_prices_it_on_the_server(): void
    {
        $coffee = $this->makeMenuItem(price: 20000);
        $rice = $this->makeMenuItem(price: 15000);

        $res = $this->submit($this->ssPayload([
            ['variant_id' => $coffee->id, 'qty' => 2],
            ['variant_id' => $rice->id, 'qty' => 1, 'note' => 'tanpa sambal'],
        ]))->assertCreated();

        $res->assertJsonPath('data.status', 'awaiting_verification')
            ->assertJsonPath('data.service_type', 'dine_in')
            ->assertJsonPath('data.table_name', 'Meja 5')
            ->assertJsonPath('data.subtotal', '55000.00')
            ->assertJsonPath('data.total_amount', '55000.00');
        $this->assertSame(40, strlen($res->json('data.receipt_token')));
        $this->assertMatchesRegularExpression('/^SS-\d{6}-\d{3}$/', $res->json('data.reference_no'));

        $row = SelfServiceOrder::firstOrFail();
        $this->assertSame($this->ssTable->id, (int) $row->table_id);
        $this->assertSame($this->ssTable->outlet_id, (int) $row->outlet_id);
        $this->assertSame('Meja 5', $row->table_name_snapshot);
        $this->assertCount(2, $row->lines);
        $this->assertSame('tanpa sambal', collect($row->lines)->firstWhere('variant_id', $rice->id)['note']);
        $this->assertSame($this->ssChannel->id, (int) $row->payment_channel_id);
    }

    public function test_nothing_downstream_is_created_before_staff_acts(): void
    {
        $item = $this->makeMenuItem(stock: 10);

        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 3]]))->assertCreated();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, KitchenTicket::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame(10, (int) $item->fresh()->current_stock, 'stok tidak boleh bergerak sebelum verifikasi');
        $this->assertSame(0, \App\Models\Payment::count());
    }

    public function test_tax_follows_the_store_setting(): void
    {
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '10', 'type' => 'string']);
        $item = $this->makeMenuItem(price: 25000);

        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 2]]))->assertCreated()
            ->assertJsonPath('data.subtotal', '50000.00')
            ->assertJsonPath('data.tax_amount', '5000.00')
            ->assertJsonPath('data.total_amount', '55000.00');

        $this->assertSame('10.00', SelfServiceOrder::first()->tax_rate_snapshot);
    }

    public function test_client_supplied_prices_discounts_outlet_or_table_are_rejected(): void
    {
        $item = $this->makeMenuItem(price: 20000);
        $base = fn (array $extra) => $this->ssPayload([['variant_id' => $item->id, 'qty' => 1]], $extra);

        foreach (['discount_amount' => 1000, 'discount_percent' => 10, 'outlet_id' => 99, 'table_id' => 99, 'total_amount' => 1, 'subtotal' => 1, 'tax_amount' => 0] as $field => $value) {
            $this->submit($base([$field => $value]))->assertStatus(422);
        }

        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1, 'unit_price' => 1]]))->assertStatus(422);
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1, 'discount_amount' => 500]]))->assertStatus(422);
        $this->assertSame(0, SelfServiceOrder::count());
    }

    public function test_item_bounds_are_enforced(): void
    {
        $item = $this->makeMenuItem();

        $this->submit($this->ssPayload([]))->assertStatus(422);
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 0]]))->assertStatus(422);
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 100]]))->assertStatus(422);
        $this->submit($this->ssPayload(array_fill(0, 51, ['variant_id' => $item->id, 'qty' => 1])))->assertStatus(422);
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1]], ['service_type' => 'retail']))->assertStatus(422);
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1, 'note' => str_repeat('x', 300)]]))->assertStatus(422);
    }

    public function test_unavailable_items_return_409_with_details_and_keep_the_proof_unused(): void
    {
        $ok = $this->makeMenuItem();
        $soldOut = $this->makeMenuItem();
        $inactive = $this->makeMenuItem(variantOverrides: ['is_active' => false]);
        $this->ssMarkSoldOut($soldOut->product);
        $payload = $this->ssPayload([
            ['variant_id' => $ok->id, 'qty' => 1], ['variant_id' => $soldOut->id, 'qty' => 1], ['variant_id' => $inactive->id, 'qty' => 1],
        ]);

        $res = $this->submit($payload)->assertStatus(409)->assertJsonPath('code', 'item_unavailable');

        $this->assertEqualsCanonicalizing([$soldOut->id, $inactive->id], collect($res->json('items'))->pluck('variant_id')->all());
        $this->assertSame(0, SelfServiceOrder::count());

        // Setelah pelanggan membuang item bermasalah, bukti yang sama masih bisa dipakai.
        $payload['items'] = [['variant_id' => $ok->id, 'qty' => 1]];
        $this->submit($payload)->assertCreated();
    }

    public function test_an_unknown_variant_is_unavailable_not_a_server_error(): void
    {
        $this->submit($this->ssPayload([['variant_id' => 999999, 'qty' => 1]]))->assertStatus(409)->assertJsonPath('code', 'item_unavailable');
    }

    public function test_resubmitting_the_same_local_ref_returns_the_first_order(): void
    {
        $item = $this->makeMenuItem();
        $payload = $this->ssPayload([['variant_id' => $item->id, 'qty' => 1]]);

        $first = $this->submit($payload)->assertCreated();
        $again = $this->submit($payload)->assertOk();

        $this->assertSame($first->json('data.receipt_token'), $again->json('data.receipt_token'));
        $this->assertSame(1, SelfServiceOrder::count());
    }

    public function test_a_proof_is_required_valid_and_single_use(): void
    {
        $item = $this->makeMenuItem();
        $items = [['variant_id' => $item->id, 'qty' => 1]];

        $noProof = $this->ssPayload($items);
        unset($noProof['proof_token']);
        $this->submit($noProof)->assertStatus(422)->assertJsonValidationErrors('proof_token');

        $this->submit($this->ssPayload($items, ['proof_token' => (string) Str::uuid()]))->assertStatus(422)->assertJsonValidationErrors('proof_token');

        $proof = $this->ssProofToken();
        $this->submit($this->ssPayload($items, ['proof_token' => $proof]))->assertCreated();
        // Bukti yang sama untuk kiriman lain (local_ref berbeda) ditolak.
        $this->submit($this->ssPayload($items, ['proof_token' => $proof]))->assertStatus(422)->assertJsonValidationErrors('proof_token');
        $this->assertSame(1, SelfServiceOrder::count());
    }

    public function test_modifier_selections_are_priced_and_snapshotted_by_the_server(): void
    {
        $item = $this->makeMenuItem(price: 20000);
        $group = ModifierGroup::factory()->create(['selection_type' => 'single', 'is_required' => true, 'min_select' => 1, 'max_select' => 1]);
        $option = ModifierOption::factory()->create(['modifier_group_id' => $group->id, 'name' => 'Extra Shot', 'price_delta' => 3000]);
        $item->product->modifierGroups()->attach($group->id, ['has_rules' => true, 'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'display_order' => 1]);

        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 2, 'modifier_selections' => [['option_id' => $option->id, 'qty' => 1]]]]))
            ->assertCreated()->assertJsonPath('data.total_amount', '46000.00');

        $line = SelfServiceOrder::first()->lines[0];
        $this->assertSame('23000.00', $line['unit_price']);
        $this->assertSame('Extra Shot', $line['modifiers'][0]['option']);
        // JSON MySQL menyusun ulang urutan kunci objek, jadi dibandingkan tanpa memedulikan urutan kunci.
        $this->assertEquals([['option_id' => $option->id, 'qty' => 1]], $line['modifier_selections']);

        // Pilihan wajib tidak diisi → ditolak resolver yang sama dengan POS.
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1]]))->assertStatus(422);
        // Id pilihan milik grup lain/tidak ada → ditolak.
        $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1, 'modifier_selections' => [['option_id' => 999999, 'qty' => 1]]]]))->assertStatus(422);
    }

    public function test_reference_numbers_are_sequential_per_outlet_per_day(): void
    {
        $item = $this->makeMenuItem();
        $items = [['variant_id' => $item->id, 'qty' => 1]];

        $one = $this->submit($this->ssPayload($items))->json('data.reference_no');
        $two = $this->submit($this->ssPayload($items))->json('data.reference_no');

        $this->assertStringEndsWith('-001', $one);
        $this->assertStringEndsWith('-002', $two);
    }

    public function test_an_inactive_table_or_missing_qris_channel_blocks_submission(): void
    {
        $item = $this->makeMenuItem();
        $items = [['variant_id' => $item->id, 'qty' => 1]];

        PaymentChannel::query()->update(['is_active' => false]);
        $this->submit($this->ssPayload($items))->assertStatus(409)->assertJsonPath('code', 'qris_unavailable');
        PaymentChannel::query()->update(['is_active' => true]);

        $this->ssTable->update(['is_active' => false]);
        $this->submit($this->ssPayload($items))->assertStatus(409)->assertJsonPath('code', 'table_unavailable');
        $this->assertSame(0, SelfServiceOrder::count());
    }

    public function test_submissions_are_rate_limited_per_table_and_ip(): void
    {
        $this->keepThrottle = true;
        $item = $this->makeMenuItem();
        $codes = [];
        for ($i = 0; $i < 7; $i++) {
            $codes[] = $this->submit($this->ssPayload([['variant_id' => $item->id, 'qty' => 1]]))->getStatusCode();
        }

        $this->assertContains(429, $codes);
        $this->assertSame(5, count(array_filter($codes, fn ($c) => $c === 201)));
        $this->postJson($this->ssUrl('/orders'), [])->assertStatus(429)->assertJsonPath('code', 'throttled');
    }
}
