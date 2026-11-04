<?php

namespace Tests\Feature;

use App\Exports\SalesTransactionsExport;
use App\Models\Artist;
use App\Models\CashierSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderReferenceImage;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Models\PaymentProof;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Ekspor "apa yang tampil di layar": klien hanya mengirim KUNCI baris ("order:12",
 * "preorder:3"); isi berkas dibangun ulang di server lewat service yang sama dengan
 * daftar, jadi nominal tak bisa dipalsukan dan berkas tak pernah berbeda dari layar.
 */
class SalesTransactionsExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/reports/sales/transactions/export';

    private User $cashier;

    private User $owner;

    private Event $event;

    private CashierSession $session;

    private $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier', 'name' => 'Kasir Satu']);
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($this->cashier, 'sanctum');

        $this->event = Event::factory()->create(['status' => 'active']);
        $this->session = CashierSession::factory()->create(['event_id' => $this->event->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $product = Product::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Nekoyama Studio'])->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Akatsuki Keychain', 'is_preorder' => true]);
        $this->variant = $product->variants()->create(['sku' => 'NEKKYAKT0001', 'sell_price' => 10000, 'cost_price' => 4000, 'current_stock' => 100]);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return app(OrderService::class)->create(array_merge([
            'session_id' => $this->session->id, 'local_ref' => (string) Str::uuid(),
            'customer_id' => Customer::factory()->create(['name' => 'Budi', 'phone' => '0812-9999-0000', 'email' => 'budi@contoh.test'])->id,
            'items' => [['variant_id' => $this->variant->id, 'qty' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ], $overrides), $this->cashier);
    }

    /** Jalankan ekspor dan kembalikan baris-baris yang DITULIS ke berkas. */
    private function exported(array $body = [], ?User $as = null): array
    {
        Excel::fake();
        if ($as) {
            $this->actingAs($as, 'sanctum');
        }
        $this->postJson(self::URL, array_merge(['event_id' => $this->event->id], $body))->assertOk();

        $rows = [];
        // 056 — dibuat lewat Excel::raw() (supaya berkas gambar sementara bisa dihapus); `rows()` = baris asosiatif.
        Excel::assertExportedInRaw(SalesTransactionsExport::class, function (SalesTransactionsExport $export) use (&$rows) {
            $rows = $export->rows();

            return true;
        });

        return $rows;
    }

    public function test_it_exports_the_whole_event_when_no_keys_are_given(): void
    {
        $this->makeOrder();
        $this->makeOrder();

        $this->assertCount(2, $this->exported());
    }

    public function test_only_the_requested_rows_are_exported(): void
    {
        $keep = $this->makeOrder();
        $this->makeOrder(); // tidak diminta

        $rows = $this->exported(['keys' => ["order:{$keep->id}"]]);

        $this->assertCount(1, $rows);
        $this->assertSame($keep->order_number, $rows[0]['transaction_no']);
    }

    public function test_rows_have_readable_columns_with_numeric_money(): void
    {
        $order = $this->makeOrder();

        $row = $this->exported(['keys' => ["order:{$order->id}"]])[0];

        $this->assertSame(
            ['transaction_no', 'status', 'time', 'customer', 'sellers', 'cashier', 'items', 'units', 'gross_sales', 'net_sales', 'discount', 'payment_methods', 'payment_status', 'verification', 'cash', 'non_cash', 'total', 'paid', 'balance', 'reference_note', 'payment_proofs'],
            array_keys($row),
        );
        $this->assertSame('Budi', $row['customer']);
        $this->assertSame('Nekoyama Studio', $row['sellers']);
        $this->assertSame('Kasir Satu', $row['cashier']);
        $this->assertSame('cash', $row['payment_methods']);
        $this->assertSame(3.0, (float) $row['units']);
        $this->assertSame(30000.0, $row['total']);   // angka, bukan string — bisa dijumlah di Excel
        $this->assertSame(30000.0, $row['cash']);
        // 028 — status pembayaran kini Unpaid/Partially Paid/Fully Paid + dibayar/sisa (angka)
        $this->assertSame('fully_paid', $row['payment_status']);
        $this->assertSame(30000.0, $row['paid']);
        $this->assertSame(0.0, $row['balance']);
    }

    public function test_a_partially_paid_sale_exports_its_paid_and_balance(): void
    {
        $order = $this->makeOrder(['payments' => [['method' => 'cash', 'amount' => 10000]]]);

        $row = $this->exported(['keys' => ["order:{$order->id}"]])[0];

        $this->assertSame('partially_paid', $row['payment_status']);
        $this->assertSame(10000.0, $row['paid']);
        $this->assertSame(20000.0, $row['balance']);
    }

    public function test_every_line_is_listed_not_just_the_two_shown_in_the_preview(): void
    {
        $extra = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster'])
            ->variants()->create(['sku' => 'POSTER0001', 'sell_price' => 5000, 'cost_price' => 1000, 'current_stock' => 50]);
        $third = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Pin'])
            ->variants()->create(['sku' => 'PIN0001', 'sell_price' => 2000, 'cost_price' => 500, 'current_stock' => 50]);
        $order = $this->makeOrder(['items' => [
            ['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $extra->id, 'qty' => 1], ['variant_id' => $third->id, 'qty' => 2],
        ], 'payments' => [['method' => 'cash', 'amount' => 40000]]]);

        $items = $this->exported(['keys' => ["order:{$order->id}"]])[0]['items'];

        $this->assertStringContainsString('Akatsuki Keychain', $items);
        $this->assertStringContainsString('Poster', $items);
        $this->assertStringContainsString('Pin', $items);   // baris ke-3 ikut, tidak dipotong
        $this->assertStringContainsString('×3', $items);
    }

    public function test_customer_contact_details_never_leave_in_the_file(): void
    {
        $this->makeOrder();

        $blob = json_encode($this->exported());

        $this->assertStringNotContainsString('0812-9999-0000', $blob);
        $this->assertStringNotContainsString('budi@contoh.test', $blob);
    }

    public function test_a_cashier_gets_no_cost_or_margin_but_an_owner_does(): void
    {
        $order = $this->makeOrder();

        $cashierRow = $this->exported(['keys' => ["order:{$order->id}"]])[0];
        $this->assertArrayNotHasKey('cost', $cashierRow);
        $this->assertArrayNotHasKey('margin', $cashierRow);

        $ownerRow = $this->exported(['keys' => ["order:{$order->id}"]], $this->owner)[0];
        $this->assertSame(12000.0, $ownerRow['cost']);     // 3 × 4.000
        $this->assertSame(18000.0, $ownerRow['margin']);   // 30.000 − 12.000
    }

    public function test_keys_that_do_not_exist_are_ignored_instead_of_inventing_rows(): void
    {
        $order = $this->makeOrder();

        $rows = $this->exported(['keys' => ["order:{$order->id}", 'order:999999']]);

        $this->assertCount(1, $rows);
    }

    public function test_pre_order_keys_are_not_accepted_because_sales_is_pos_only(): void
    {
        $this->makeOrder();

        $this->postJson(self::URL, ['keys' => ['preorder:1']])->assertStatus(422);
    }

    public function test_keys_from_another_event_are_not_leaked_when_an_event_filter_is_given(): void
    {
        $mine = $this->makeOrder();
        $otherEvent = Event::factory()->create(['status' => 'active']);
        $otherSession = CashierSession::factory()->create(['event_id' => $otherEvent->id, 'user_id' => $this->cashier->id, 'status' => 'open']);
        $foreign = $this->makeOrder(['session_id' => $otherSession->id]);

        $rows = $this->exported(['keys' => ["order:{$mine->id}", "order:{$foreign->id}"]]);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->order_number, $rows[0]['transaction_no']);
    }

    public function test_voided_orders_are_only_exported_when_asked_for(): void
    {
        $this->makeOrder();
        $voided = $this->makeOrder();
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$voided->id}/void", ['reason' => 'Salah input'])->assertOk();

        $this->assertCount(1, $this->exported());
        $rows = $this->exported(['include_voided' => true]);
        $this->assertCount(2, $rows);
        $this->assertContains('voided', array_column($rows, 'status'));
    }

    public function test_malformed_keys_and_oversized_requests_are_rejected(): void
    {
        $this->postJson(self::URL, ['keys' => ['drop table']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => ['order:abc']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => ['preorder:12']])->assertStatus(422);
        $this->postJson(self::URL, ['keys' => array_fill(0, 5001, 'order:1')])->assertStatus(422);
    }

    // --- 056 (US8/US9): berkas penuh dengan gambar ------------------------------------------------

    /** JPEG nyata kecil di disk privat `local` (Storage::fake), supaya GD bisa mendekodenya. */
    private function fakeImage(string $path): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return $path;
    }

    private function addReference(Order $order, array $paths, ?string $note = 'Catatan WA'): void
    {
        $order->update(['reference_note' => $note, 'reference_updated_at' => now(), 'reference_updated_by' => $this->cashier->id]);
        foreach ($paths as $i => $path) {
            OrderReferenceImage::create(['order_id' => $order->id, 'path' => $path, 'mime' => 'image/jpeg', 'size_bytes' => 10, 'position' => $i, 'uploaded_by' => $this->cashier->id]);
        }
    }

    private function addProof(Order $order, string $path, ?User $recorder = null, ?User $uploader = null, string $mime = 'image/jpeg'): Payment
    {
        $channel = PaymentChannel::create(['type' => 'qr_ewallet', 'provider' => 'Shopee', 'account_name' => 'T', 'is_active' => true]);
        $payment = Payment::create(['order_id' => $order->id, 'method' => 'qr_ewallet', 'purpose' => 'settlement', 'amount' => 1000,
            'verification' => 'pending', 'paid_at' => now(), 'channel_id' => $channel->id, 'recorded_by' => $recorder?->id]);
        PaymentProof::create(['proof_token' => (string) Str::uuid(), 'payment_id' => $payment->id, 'file_path' => $path, 'mime_type' => $mime,
            'file_size' => 10, 'captured_via' => 'upload', 'uploaded_by' => $uploader?->id, 'created_at' => now()]);

        return $payment;
    }

    /** Ekspor NYATA (tanpa fake) dibuka kembali: [baris-baris sheet, jumlah gambar tertanam]. */
    private function realWorkbook(array $body = [], ?User $as = null): array
    {
        if ($as) {
            $this->actingAs($as, 'sanctum');
        }
        $response = $this->postJson(self::URL, array_merge(['event_id' => $this->event->id], $body))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'sx').'.xlsx';
        file_put_contents($file, $response->getContent());
        $sheet = IOFactory::load($file)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        @unlink($file);

        return [$rows, $sheet->getDrawingCollection()->count()];
    }

    public function test_it_embeds_reference_and_viewable_proof_images_and_carries_the_note(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder();
        $this->addReference($order, [$this->fakeImage('order-reference-images/a.jpg'), $this->fakeImage('order-reference-images/b.jpg')]);
        $this->addProof($order, $this->fakeImage('payment-proofs/p.jpg'), $this->cashier, $this->cashier);

        [$rows, $drawings] = $this->realWorkbook(['keys' => ["order:{$order->id}"]]);

        $this->assertSame(3, $drawings);                      // 2 referensi + 1 bukti, tertanam di baris itu
        $head = array_flip($rows[0]);
        $this->assertSame('Catatan WA', $rows[1][$head['reference_note']]);
        $this->assertStringContainsString('qr_ewallet', $rows[1][$head['payment_proofs']]);
        $this->assertSame('pending', $rows[1][$head['verification']]);
    }

    public function test_a_proof_the_exporter_may_not_view_is_absent_without_a_trace(): void
    {
        Storage::fake('local');
        $other = User::factory()->create(['role' => 'cashier']);
        $order = $this->makeOrder();
        $this->addProof($order, $this->fakeImage('payment-proofs/secret.jpg'), $this->cashier, $this->cashier);

        [$rows, $drawings] = $this->realWorkbook(['keys' => ["order:{$order->id}"]], $other);

        $this->assertSame(0, $drawings);
        $head = array_flip($rows[0]);
        $this->assertSame('', (string) $rows[1][$head['payment_proofs']]);
        $this->assertStringNotContainsString('secret', json_encode($rows));
    }

    public function test_an_unreadable_or_non_image_proof_does_not_fail_the_export(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder();
        Storage::disk('local')->put('payment-proofs/doc.pdf', '%PDF-1.4 not an image');
        $this->addProof($order, 'payment-proofs/doc.pdf', $this->cashier, $this->cashier, 'application/pdf');
        $this->addReference($order, ['order-reference-images/missing.jpg']);   // berkas tak ada

        [$rows, $drawings] = $this->realWorkbook(['keys' => ["order:{$order->id}"]]);

        $this->assertSame(0, $drawings);
        $this->assertStringContainsString(__('sales_export.not_embedded'), json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    public function test_exceeding_the_image_limit_is_refused_with_a_clear_message_not_a_partial_file(): void
    {
        Storage::fake('local');
        config(['sales.export_max_images' => 2]);
        $order = $this->makeOrder();
        $this->addReference($order, [$this->fakeImage('r/1.jpg'), $this->fakeImage('r/2.jpg'), $this->fakeImage('r/3.jpg')]);

        $this->postJson(self::URL, ['event_id' => $this->event->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('sales_export.too_many_images', ['max' => 2]));
    }

    public function test_the_columns_follow_the_screen_order_with_total_before_margin_for_an_owner(): void
    {
        $order = $this->makeOrder();

        [$rows] = $this->realWorkbook(['keys' => ["order:{$order->id}"]], $this->owner);

        $head = $rows[0];
        $this->assertSame(array_search('total', $head) + 1, array_search('cost', $head));
        $this->assertSame(array_search('cost', $head) + 1, array_search('margin', $head));
    }

    public function test_cash_only_sales_have_no_verification_status(): void
    {
        $order = $this->makeOrder();

        $this->assertSame('', $this->exported(['keys' => ["order:{$order->id}"]])[0]['verification']);
    }

    public function test_with_a_seller_filter_units_gross_and_net_cover_only_that_sellers_items(): void
    {
        $second = Product::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Yuki'])->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster'])
            ->variants()->create(['sku' => 'YUKI0001', 'sell_price' => 7000, 'cost_price' => 1000, 'current_stock' => 50]);
        $order = $this->makeOrder(['items' => [['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $second->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 44000]]]);
        $keys = ['keys' => ["order:{$order->id}"]];

        $whole = $this->exported($keys)[0];
        $this->assertSame(44000.0, $whole['gross_sales']);

        $scoped = $this->exported($keys + ['sellers' => ['Yuki']])[0];
        $this->assertSame(2.0, (float) $scoped['units']);
        $this->assertSame(14000.0, $scoped['gross_sales']);
        $this->assertSame(14000.0, $scoped['net_sales']);
        $this->assertSame(44000.0, $scoped['total']);       // Total tetap utuh (pembayaran tak dibagi per penjual)
    }

    public function test_it_requires_a_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::URL, [])->assertStatus(401);
    }
}
