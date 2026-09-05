<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreorderTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($user, 'sanctum');

        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);
        $this->variant = $product->variants()->create([
            'sku' => 'RYUKYFIG0001', 'sell_price' => 300000, 'cost_price' => 150000, 'current_stock' => 0,
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function createPreorder(): array
    {
        return $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->json();
    }

    public function test_creating_preorder_does_not_reduce_stock(): void
    {
        $this->createPreorder();
        $this->assertSame(0, $this->variant->fresh()->current_stock);
    }

    public function test_preorder_requires_customer(): void
    {
        $response = $this->postJson('/api/v1/preorders', [
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ]);
        $response->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_recording_down_payment_moves_status_from_ordered_to_dp_paid(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);

        $response->assertCreated()->assertJsonPath('status', 'dp_paid');
        $this->assertEquals('100000.00', $response->json('paid_amount'));
    }

    public function test_arrived_status_increases_stock(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        // Lompat langsung dp_paid tidak valid tanpa payment, tapi untuk
        // menguji efek stok kita transisi manual lewat endpoint status.

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $this->assertSame(1, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'purchase', 'qty_change' => 1]);
    }

    public function test_cannot_skip_from_ordered_directly_to_handed_over(): void
    {
        $preorder = $this->createPreorder();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $response->assertStatus(409);
    }

    public function test_handed_over_rejected_when_not_fully_paid(): void
    {
        $preorder = $this->createPreorder(); // total 300000, belum bayar
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'settled'])->assertOk();
        // Belum bayar penuh -> settled tercapai tapi handed_over harus gagal
        $handoverAttempt = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $handoverAttempt->assertStatus(409);
        $this->assertSame(1, $this->variant->fresh()->current_stock); // stok tidak berkurang
    }

    public function test_handed_over_succeeds_when_fully_paid_and_decreases_stock(): void
    {
        $preorder = $this->createPreorder();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'dp_paid'])->assertOk();
        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        // Lunasi penuh (300000) — otomatis pindah ke 'settled'.
        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 300000, 'purpose' => 'settlement',
        ])->assertCreated()->assertJsonPath('status', 'settled');

        $response = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'handed_over']);

        $response->assertOk()->assertJsonPath('status', 'handed_over');
        $this->assertSame(0, $this->variant->fresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', ['variant_id' => $this->variant->id, 'type' => 'preorder_handover', 'qty_change' => -1]);
    }

    public function test_response_includes_customer_payments_and_shipment(): void
    {
        // BUG YANG DITEMUKAN & DIPERBAIKI — ditemukan lewat verifikasi
        // browser sungguhan saat integrasi frontend, bukan lewat test yang
        // sudah ada di sini sebelumnya. present() sudah lama mengembalikan
        // 'items' tapi diam-diam tidak pernah menyertakan customer/payments/
        // shipment sama sekali di respons manapun (store/show/updateStatus/
        // storePayment), padahal ketiganya sudah didokumentasikan di
        // openapi-pos-mvp.yaml dan show() sudah meng-eager-load semuanya.

        $preorder = $this->createPreorder();
        $this->assertSame($this->customer->id, $preorder['customer']['id']);
        $this->assertSame([], $preorder['payments']);
        $this->assertNull($preorder['shipment']);

        $afterDp = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ])->json();
        $this->assertSame($this->customer->id, $afterDp['customer']['id']);
        $this->assertCount(1, $afterDp['payments']);
        $this->assertSame('down_payment', $afterDp['payments'][0]['purpose']);
        $this->assertSame('100000.00', $afterDp['payments'][0]['amount']);

        $show = $this->getJson("/api/v1/preorders/{$preorder['id']}")->json();
        $this->assertSame($this->customer->name, $show['customer']['name']);
        $this->assertCount(1, $show['payments']);

        $afterStatus = $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->json();
        $this->assertSame($this->customer->id, $afterStatus['customer']['id']);
    }

    public function test_shipment_appears_in_response_once_created(): void
    {
        $product = $this->variant->product()->update(['is_preorder' => true]);
        $courierPreorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'courier',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->json();

        $this->postJson("/api/v1/preorders/{$courierPreorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test', 'city' => 'Jakarta',
        ])->assertCreated();

        $show = $this->getJson("/api/v1/preorders/{$courierPreorder['id']}")->json();
        $this->assertSame('JNE', $show['shipment']['courier_name']);
    }

    public function test_shipment_can_only_be_created_for_courier_fulfillment(): void
    {
        $preorder = $this->createPreorder(); // fulfillment = pickup

        $response = $this->postJson("/api/v1/preorders/{$preorder['id']}/shipment", [
            'courier_name' => 'JNE', 'recipient_name' => 'Budi', 'recipient_phone' => '08123',
            'address_line' => 'Jl. Test', 'city' => 'Jakarta',
        ]);

        $response->assertStatus(409);
    }

    /**
     * 010-split-payment-preorder-reports (US2, T009) — verifikasi bahwa
     * POST /preorders/{id}/payments yang TIDAK diubah sama sekali sudah
     * benar-benar aman dipanggil berurutan (sequential) untuk mensimulasikan
     * split payment dari frontend (lihat research.md R2: tidak ada endpoint
     * batch baru, FE hanya memanggil endpoint ini N kali berturut-turut).
     */
    public function test_sequential_payment_calls_each_create_own_row_and_sum_paid_amount(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        $first = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);
        $first->assertCreated();
        $this->assertEquals('100000.00', $first->json('paid_amount'));

        $second = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'qr_ewallet', 'amount' => 50000, 'purpose' => 'down_payment',
            'channel_id' => $this->createPaymentChannel()->id,
            'proof_token' => $this->createProofToken(),
        ]);
        $second->assertCreated();

        // 2 baris terpisah di tabel payments, bukan digabung jadi satu.
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['preorder_id' => $preorder['id'], 'method' => 'cash', 'amount' => 100000]);
        $this->assertDatabaseHas('payments', ['preorder_id' => $preorder['id'], 'method' => 'qr_ewallet', 'amount' => 50000]);

        // paid_amount/outstanding mencerminkan SUM kedua panggilan, bukan
        // hanya panggilan terakhir.
        $this->assertEquals('150000.00', $second->json('paid_amount'));
        $this->assertEquals('150000.00', $second->json('outstanding'));
    }

    public function test_sequential_down_payment_then_settlement_transitions_status_like_single_calls_would(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        // Panggilan 1: down_payment via cash -> status ordered -> dp_paid,
        // identik dengan test_recording_down_payment_moves_status_from_ordered_to_dp_paid.
        $afterDp = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ]);
        $afterDp->assertCreated()->assertJsonPath('status', 'dp_paid');

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        // Panggilan 2 (split entry kedua di tahap settlement): settlement
        // via bank_transfer melunasi sisa 200000 -> status arrived -> settled,
        // identik dengan test_handed_over_succeeds_when_fully_paid_and_decreases_stock.
        $afterSettlement = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'bank_transfer', 'amount' => 200000, 'purpose' => 'settlement',
            'channel_id' => $this->createPaymentChannel()->id,
            'proof_token' => $this->createProofToken(),
        ]);
        $afterSettlement->assertCreated()->assertJsonPath('status', 'settled');
        $this->assertEquals('300000.00', $afterSettlement->json('paid_amount'));
        $this->assertEquals('0.00', $afterSettlement->json('outstanding'));

        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * BUG-CHECK (bukan bug baru) — dibaca dari PreorderService::recordPayment()
     * dan PaymentRecorder::record(): tidak ada guard "tolak jika amount >
     * outstanding" untuk preorder sama sekali (berbeda dari cash-overpay
     * guard di alur POS/orders). Ini perilaku existing yang tidak diubah
     * oleh fitur 010 — jadi panggilan ketiga setelah lunas TETAP diterima
     * (201) dan menambah paid_amount melebihi total_amount, sama seperti
     * satu panggilan over-payment tunggal hari ini juga akan diterima.
     * Test ini mengunci perilaku itu supaya regresi (guard baru yang tidak
     * disengaja, atau guard yang hilang) ketahuan.
     */
    public function test_third_sequential_call_after_fully_paid_is_still_accepted_no_overpay_guard_exists(): void
    {
        $preorder = $this->createPreorder(); // total 300000

        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $this->patchJson("/api/v1/preorders/{$preorder['id']}/status", ['status' => 'arrived'])->assertOk();

        $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 200000, 'purpose' => 'settlement',
        ])->assertCreated()->assertJsonPath('status', 'settled');

        // Panggilan ketiga: preorder sudah lunas (paid_amount == total_amount),
        // tapi karena tidak ada guard over-payment untuk preorder, panggilan
        // ini tetap sukses (201), bukan ditolak.
        $third = $this->postJson("/api/v1/preorders/{$preorder['id']}/payments", [
            'method' => 'cash', 'amount' => 50000, 'purpose' => 'settlement',
        ]);

        $third->assertCreated();
        $this->assertEquals('350000.00', $third->json('paid_amount'));
        $this->assertDatabaseCount('payments', 3);
    }

    /**
     * 013-preorder-list-filters-receipt (T006/T007) — bikin variant kedua
     * dari artist yang berbeda dengan $this->variant, supaya tes bisa
     * membuat preorder lintas-penjual.
     */
    private function createVariantForNewArtist(): ProductVariant
    {
        $artist = Artist::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'artist_id' => $artist->id, 'category_id' => $category->id, 'is_preorder' => true,
        ]);

        return $product->variants()->create([
            'sku' => 'OTHERFIG0001', 'sell_price' => 200000, 'cost_price' => 100000, 'current_stock' => 0,
        ]);
    }

    public function test_artist_id_filter_returns_only_preorders_with_matching_item_artist(): void
    {
        $matching = $this->createPreorder();

        $otherVariant = $this->createVariantForNewArtist();
        $nonMatching = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $otherVariant->id, 'qty' => 1]],
        ])->json();

        $artistId = $this->variant->product->artist_id;

        $response = $this->getJson("/api/v1/preorders?artist_id={$artistId}");
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matching['id']));
        $this->assertFalse($ids->contains($nonMatching['id']));
    }

    public function test_artist_id_filter_combines_with_status_filter(): void
    {
        $preorder = $this->createPreorder();
        $artistId = $this->variant->product->artist_id;

        // preorder baru tanpa pembayaran masih berstatus 'ordered'
        $matchingOrdered = $this->getJson("/api/v1/preorders?artist_id={$artistId}&status=ordered");
        $matchingOrdered->assertOk();
        $this->assertTrue(collect($matchingOrdered->json('data'))->pluck('id')->contains($preorder['id']));

        $matchingDpPaid = $this->getJson("/api/v1/preorders?artist_id={$artistId}&status=dp_paid");
        $matchingDpPaid->assertOk();
        $this->assertFalse(collect($matchingDpPaid->json('data'))->pluck('id')->contains($preorder['id']));
    }

    public function test_preorder_with_multiple_items_same_artist_appears_once_in_filtered_list(): void
    {
        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [
                ['variant_id' => $this->variant->id, 'qty' => 1],
                ['variant_id' => $this->variant->id, 'qty' => 1],
                ['variant_id' => $this->variant->id, 'qty' => 1],
            ],
        ])->json();

        $artistId = $this->variant->product->artist_id;

        $response = $this->getJson("/api/v1/preorders?artist_id={$artistId}");
        $response->assertOk();

        $matching = collect($response->json('data'))->filter(fn ($row) => $row['id'] === $preorder['id']);
        $this->assertCount(1, $matching);
    }

    public function test_list_and_detail_responses_include_sellers(): void
    {
        $preorder = $this->createPreorder();
        $artist = $this->variant->product->artist;

        $listResponse = $this->getJson('/api/v1/preorders');
        $listResponse->assertOk();

        $row = collect($listResponse->json('data'))->firstWhere('id', $preorder['id']);
        $this->assertNotNull($row);
        $this->assertEquals([['id' => $artist->id, 'name' => $artist->name]], $row['sellers']);

        $detailResponse = $this->getJson("/api/v1/preorders/{$preorder['id']}");
        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('sellers', [['id' => $artist->id, 'name' => $artist->name]]);
        $detailResponse->assertJsonPath('items.0.artist_id', $artist->id);
        $detailResponse->assertJsonPath('items.0.artist_name', $artist->name);
    }

    public function test_preorder_with_items_from_two_different_artists_shows_both_in_sellers(): void
    {
        $otherVariant = $this->createVariantForNewArtist();

        $preorder = $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [
                ['variant_id' => $this->variant->id, 'qty' => 1],
                ['variant_id' => $otherVariant->id, 'qty' => 1],
            ],
        ])->json();

        $firstArtist = $this->variant->product->artist;
        $secondArtist = $otherVariant->product->artist;

        $detailResponse = $this->getJson("/api/v1/preorders/{$preorder['id']}");
        $detailResponse->assertOk();

        $sellerIds = collect($detailResponse->json('sellers'))->pluck('id');
        $this->assertCount(2, $sellerIds);
        $this->assertTrue($sellerIds->contains($firstArtist->id));
        $this->assertTrue($sellerIds->contains($secondArtist->id));

        $listResponse = $this->getJson('/api/v1/preorders');
        $row = collect($listResponse->json('data'))->firstWhere('id', $preorder['id']);
        $listSellerIds = collect($row['sellers'])->pluck('id');
        $this->assertCount(2, $listSellerIds);
        $this->assertTrue($listSellerIds->contains($firstArtist->id));
        $this->assertTrue($listSellerIds->contains($secondArtist->id));
    }

    private function createProofToken(): string
    {
        $proof = \App\Models\PaymentProof::create([
            'proof_token' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => 'payment-proofs/test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'uploaded_by' => \App\Models\User::first()->id,
        ]);

        return $proof->proof_token;
    }

    private function createPaymentChannel(): \App\Models\PaymentChannel
    {
        return \App\Models\PaymentChannel::create([
            'type' => 'bank_transfer', 'provider' => 'BCA',
            'account_name' => 'Test', 'account_number' => '123', 'is_active' => true,
        ]);
    }

    /**
     * 013-preorder-list-filters-receipt (T024, FR-010-013) — transaction_count/
     * grand_total/total_outstanding cocok dengan penjumlahan manual atas
     * beberapa preorder berstatus berbeda: satu 'ordered' (300000, belum
     * dibayar), satu 'dp_paid' (300000, dibayar 100000 lewat down_payment).
     */
    public function test_summary_totals_match_manual_sum_of_seeded_preorders(): void
    {
        $ordered = $this->createPreorder();

        $dpPaid = $this->createPreorder();
        $this->postJson("/api/v1/preorders/{$dpPaid['id']}/payments", [
            'method' => 'cash', 'amount' => 100000, 'purpose' => 'down_payment',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/preorders/summary');
        $response->assertOk();

        // 2 preorder, masing-masing 300000 -> grand_total 600000
        // outstanding = 600000 - 100000 (baru dp_paid yang sudah dibayar) = 500000
        $this->assertEquals(2, $response->json('transaction_count'));
        $this->assertEquals('600000.00', $response->json('grand_total'));
        $this->assertEquals('500000.00', $response->json('total_outstanding'));

        $byStatus = collect($response->json('by_status'))->keyBy('status');
        $this->assertEquals(1, $byStatus['ordered']['count']);
        $this->assertEquals('300000.00', $byStatus['ordered']['total_amount']);
        $this->assertEquals(1, $byStatus['dp_paid']['count']);
        $this->assertEquals('300000.00', $byStatus['dp_paid']['total_amount']);
    }

    /**
     * FR-011 — keenam status harus selalu muncul, termasuk yang tidak
     * punya baris sama sekali di himpunan yang difilter (zero-filled
     * "0.00", bukan hilang dari array).
     */
    public function test_summary_by_status_always_lists_all_six_statuses_zero_filled(): void
    {
        $this->createPreorder();

        $response = $this->getJson('/api/v1/preorders/summary');
        $response->assertOk();

        $byStatus = collect($response->json('by_status'))->keyBy('status');

        $this->assertEquals(
            ['ordered', 'dp_paid', 'arrived', 'settled', 'handed_over', 'cancelled'],
            $byStatus->keys()->all()
        );
        $this->assertEquals(0, $byStatus['handed_over']['count']);
        $this->assertEquals('0.00', $byStatus['handed_over']['total_amount']);
        $this->assertEquals(0, $byStatus['cancelled']['count']);
        $this->assertEquals('0.00', $byStatus['cancelled']['total_amount']);
        $this->assertEquals(1, $byStatus['ordered']['count']);
        $this->assertEquals('300000.00', $byStatus['ordered']['total_amount']);
    }

    /**
     * FR-013 — filter yang sama (artist_id, status, fulfillment) yang
     * dipakai index() harus mempersempit summary() secara identik, lewat
     * applyFilters() yang sama (research.md R4).
     */
    public function test_summary_respects_artist_id_status_and_fulfillment_filters(): void
    {
        $matching = $this->createPreorder();

        $otherVariant = $this->createVariantForNewArtist();
        $this->postJson('/api/v1/preorders', [
            'customer_id' => $this->customer->id,
            'fulfillment' => 'pickup',
            'items' => [['variant_id' => $otherVariant->id, 'qty' => 1]],
        ])->assertCreated();

        $artistId = $this->variant->product->artist_id;

        $response = $this->getJson("/api/v1/preorders/summary?artist_id={$artistId}&status=ordered&fulfillment=pickup");
        $response->assertOk();

        $this->assertEquals(1, $response->json('transaction_count'));
        $this->assertEquals('300000.00', $response->json('grand_total'));

        $mismatchedStatus = $this->getJson("/api/v1/preorders/summary?artist_id={$artistId}&status=dp_paid");
        $mismatchedStatus->assertOk();
        $this->assertEquals(0, $mismatchedStatus->json('transaction_count'));
        $this->assertEquals('0.00', $mismatchedStatus->json('grand_total'));
    }

    /**
     * DEMO/LIVE isolation (CLAUDE.md) — Preorder pakai HasDataMode + global
     * scope, jadi summary() (query Eloquent biasa lewat applyFilters())
     * seharusnya sudah otomatis mengecualikan baris DEMO saat berjalan di
     * mode LIVE. Tes ini membuktikan itu, bukan sekadar mengasumsikannya.
     */
    public function test_summary_excludes_demo_mode_preorder_when_running_in_live_mode(): void
    {
        \App\Support\ModeGate::runAs('live', fn () => $this->createPreorder());

        \App\Support\ModeGate::runAs('demo', function () {
            $demoCustomer = Customer::factory()->create();
            $demoArtist = Artist::factory()->create();
            $demoCategory = Category::factory()->create();
            $demoProduct = Product::factory()->create([
                'artist_id' => $demoArtist->id, 'category_id' => $demoCategory->id, 'is_preorder' => true,
            ]);
            $demoVariant = $demoProduct->variants()->create([
                'sku' => 'DEMOFIG0001', 'sell_price' => 300000, 'cost_price' => 150000, 'current_stock' => 0,
            ]);

            $this->postJson('/api/v1/preorders', [
                'customer_id' => $demoCustomer->id,
                'fulfillment' => 'pickup',
                'items' => [['variant_id' => $demoVariant->id, 'qty' => 1]],
            ])->assertCreated();
        });

        $response = \App\Support\ModeGate::runAs('live', fn () => $this->getJson('/api/v1/preorders/summary'));
        $response->assertOk();

        // Hanya 1 (LIVE) yang terhitung, bukan 2 (LIVE + DEMO).
        $this->assertEquals(1, $response->json('transaction_count'));
        $this->assertEquals('300000.00', $response->json('grand_total'));
    }
}
