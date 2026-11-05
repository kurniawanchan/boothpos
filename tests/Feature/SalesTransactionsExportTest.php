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

        // Spec 062: enam kolom dibuang dari BERKAS (layar Sales tak berubah).
        foreach (['customer', 'gross_sales', 'discount', 'cost', 'margin', 'paid'] as $removed) {
            $this->assertArrayNotHasKey($removed, $row, "kolom '{$removed}' seharusnya sudah dibuang dari ekspor");
        }
        $this->assertSame('Nekoyama Studio', $row['sellers']);
        $this->assertSame('Kasir Satu', $row['cashier']);
        $this->assertSame('cash', $row['payment_methods']);
        $this->assertSame(3.0, (float) $row['units']);
        $this->assertSame(30000.0, $row['total']);   // angka, bukan string — bisa dijumlah di Excel
        $this->assertSame(30000.0, $row['cash']);
        // 028 — status pembayaran kini Unpaid/Partially Paid/Fully Paid + dibayar/sisa (angka)
        $this->assertSame('fully_paid', $row['payment_status']);
        $this->assertSame(0.0, $row['balance']);
    }

    public function test_a_partially_paid_sale_exports_its_balance_without_a_paid_column(): void
    {
        $order = $this->makeOrder(['payments' => [['method' => 'cash', 'amount' => 10000]]]);

        $row = $this->exported(['keys' => ["order:{$order->id}"]])[0];

        $this->assertSame('partially_paid', $row['payment_status']);
        $this->assertArrayNotHasKey('paid', $row);
        $this->assertSame(20000.0, $row['balance']);
    }

    /** Varian baru dengan produk + kategori bernama; mengembalikan [varian, nama kategori]. */
    private function variantIn(string $product, string $sku, string $category, int $price = 5000): array
    {
        $cat = Category::factory()->create(['name' => $category]);
        $variant = Product::factory()->create(['artist_id' => Artist::factory()->create()->id, 'category_id' => $cat->id, 'name' => $product])
            ->variants()->create(['sku' => $sku, 'sell_price' => $price, 'cost_price' => 1000, 'current_stock' => 50]);

        return [$variant, $cat];
    }

    public function test_every_line_is_listed_one_per_bulleted_line_with_its_category(): void
    {
        Category::whereKey($this->variant->product->category_id)->update(['name' => 'Keychain']);
        [$poster] = $this->variantIn('Poster', 'POSTER0001', 'Print');
        [$pin] = $this->variantIn('Pin', 'PIN0001', 'Badge', 2000);
        $order = $this->makeOrder(['items' => [
            ['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $poster->id, 'qty' => 1], ['variant_id' => $pin->id, 'qty' => 2],
        ], 'payments' => [['method' => 'cash', 'amount' => 40000]]]);

        $items = $this->exportedRow($order)['items'];
        $lines = explode("\n", $items);

        // Spec 062: satu baris per item, berpeluru, dengan kategori; baris ke-3 tidak dipotong.
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('• ', $line);
        }
        $this->assertStringContainsString('Akatsuki Keychain', $lines[0]);
        $this->assertStringContainsString('×3 (Keychain)', $lines[0]);
        $this->assertStringContainsString('Poster', $lines[1]);
        $this->assertStringContainsString('×1 (Print)', $lines[1]);
        $this->assertStringContainsString('×2 (Badge)', $lines[2]);
    }

    public function test_a_single_item_has_one_bulleted_line_and_deleted_categories_products_and_variants_keep_their_line(): void
    {
        $single = $this->makeOrder();
        $this->assertCount(1, explode("\n", $this->exportedRow($single)['items']));

        // Kategori dihapus lunak: namanya tetap terbaca (data riwayat), baris tidak hilang.
        [$gone, $cat] = $this->variantIn('Stiker', 'STK0001', 'Kategori Lama');
        $order = $this->makeOrder(['items' => [['variant_id' => $gone->id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 5000]]]);
        $cat->delete();
        $this->assertStringContainsString('×1 (Kategori Lama)', $this->exportedRow($order)['items']);

        // Varian dan produk dihapus lunak: baris tetap ada DAN kategorinya tetap terbaca (cakupan dilepas di tiap tingkat).
        // (Produk tanpa kategori tak bisa ada: kolom wajib dan restrictOnDelete; cabang "tanpa kategori" hanya pertahanan.)
        [$gone2] = $this->variantIn('Gantungan', 'GTG0001', 'Apa Saja');
        $order2 = $this->makeOrder(['items' => [['variant_id' => $gone2->id, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 10000]]]);
        $product = $gone2->product;
        $gone2->delete();
        $product->delete();
        $line = $this->exportedRow($order2)['items'];
        $this->assertStringStartsWith('• Gantungan', $line);
        $this->assertStringContainsString('×2 (Apa Saja)', $line);
    }

    public function test_text_that_starts_with_an_equals_sign_stays_plain_text_in_the_file(): void
    {
        $order = $this->makeOrder();
        $this->addPayment($order, 'qr_ewallet', '=1+1', '=SUM(A1)');

        [$rows] = $this->realWorkbook(['keys' => ["order:{$order->id}"]]);
        $head = $rows[0];
        $row = $rows[1];

        // Seandainya dianggap rumus, selnya akan berisi hasil hitung (2), bukan teks aslinya.
        $this->assertSame('=1+1', $row[array_search('qr_type', $head)]);
        $this->assertSame('qr_ewallet: =SUM(A1)', $row[array_search('payment_note', $head)]);
    }

    public function test_the_items_and_notes_do_not_add_queries_per_sale(): void
    {
        $this->addPayment($this->makeOrder(), 'qr_ewallet', 'GoPay', 'x');
        $this->addPayment($this->makeOrder(), 'qr_ewallet', 'GoPay', 'x');
        $this->exported(); // pemanasan: cache lisensi/peran terisi pada permintaan pertama

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->exported();
        $small = count(\DB::getQueryLog());

        for ($i = 0; $i < 4; $i++) {
            $this->addPayment($this->makeOrder(), 'qr_ewallet', 'Shopee', 'y');
        }
        \DB::flushQueryLog();
        $this->exported();
        $large = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame($small, $large, 'jumlah query ekspor harus tetap saat penjualan bertambah');
    }

    public function test_multi_line_cells_wrap_and_rows_are_tall_enough(): void
    {
        Storage::fake('local');
        [$a] = $this->variantIn('Poster', 'POSTER0002', 'Print');
        [$b] = $this->variantIn('Pin', 'PIN0002', 'Badge', 2000);
        $three = $this->makeOrder(['items' => [
            ['variant_id' => $this->variant->id, 'qty' => 1], ['variant_id' => $a->id, 'qty' => 1], ['variant_id' => $b->id, 'qty' => 1],
        ], 'payments' => [['method' => 'cash', 'amount' => 17000]]]);
        $withPicture = $this->makeOrder();
        $this->addReference($withPicture, [$this->fakeImage('order-reference-images/t.jpg')]);

        $response = $this->postJson(self::URL, ['event_id' => $this->event->id])->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'sx').'.xlsx';
        file_put_contents($file, $response->getContent());
        $sheet = IOFactory::load($file)->getActiveSheet();
        @unlink($file);

        $all = $sheet->toArray(null, true, true, false);
        $head = $all[0];
        $itemsCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('items', $head) + 1);
        $noteCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search('payment_note', $head) + 1);
        // Baris dicari lewat nomor transaksi (urutan baris bergantung pada urutan kueri, bukan pada urutan pembuatan).
        $rowOf = fn (Order $o) => array_search($o->order_number, array_column($all, array_search('transaction_no', $head))) + 1;

        $this->assertTrue($sheet->getStyle($itemsCol.$rowOf($three))->getAlignment()->getWrapText());
        $this->assertTrue($sheet->getStyle($noteCol.$rowOf($three))->getAlignment()->getWrapText());
        $this->assertGreaterThanOrEqual(45, $sheet->getRowDimension($rowOf($three))->getRowHeight(), '3 baris item harus terlihat semua');
        $this->assertGreaterThanOrEqual(70, $sheet->getRowDimension($rowOf($withPicture))->getRowHeight(), 'baris bergambar tak boleh lebih pendek dari 70 pt');
    }

    public function test_customer_contact_details_never_leave_in_the_file(): void
    {
        $this->makeOrder();

        // Spec 062: sekarang NAMA pelanggan juga tak boleh ada. Dicek pada berkas SUNGGUHAN, semua sel.
        [$rows] = $this->realWorkbook();
        $blob = json_encode($rows);

        $this->assertStringNotContainsString('Budi', $blob);
        $this->assertStringNotContainsString('0812-9999-0000', $blob);
        $this->assertStringNotContainsString('budi@contoh.test', $blob);
    }

    public function test_neither_a_cashier_nor_an_owner_gets_cost_or_margin_in_the_file(): void
    {
        $order = $this->makeOrder();

        foreach ([$this->cashier, $this->owner] as $who) {
            $row = $this->exported(['keys' => ["order:{$order->id}"]], $who)[0];
            $this->assertArrayNotHasKey('cost', $row);
            $this->assertArrayNotHasKey('margin', $row);
        }
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

    public function test_kept_columns_keep_their_relative_order(): void
    {
        $order = $this->makeOrder();

        [$rows] = $this->realWorkbook(['keys' => ["order:{$order->id}"]], $this->owner);

        $expected = ['transaction_no', 'status', 'time', 'sellers', 'cashier', 'items', 'units', 'net_sales', 'payment_methods', 'payment_status', 'verification', 'cash', 'non_cash', 'total', 'balance', 'reference_note', 'payment_proofs'];
        $this->assertSame($expected, array_values(array_intersect($rows[0], $expected)));
    }

    public function test_cash_only_sales_have_no_verification_status(): void
    {
        $order = $this->makeOrder();

        $this->assertSame('', $this->exported(['keys' => ["order:{$order->id}"]])[0]['verification']);
    }

    public function test_with_a_seller_filter_units_and_net_cover_only_that_sellers_items(): void
    {
        $second = Product::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Yuki'])->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster'])
            ->variants()->create(['sku' => 'YUKI0001', 'sell_price' => 7000, 'cost_price' => 1000, 'current_stock' => 50]);
        $order = $this->makeOrder(['items' => [['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $second->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 44000]]]);
        $keys = ['keys' => ["order:{$order->id}"]];

        $whole = $this->exported($keys)[0];
        $this->assertSame(44000.0, $whole['net_sales']);

        $scoped = $this->exported($keys + ['sellers' => ['Yuki']])[0];
        $this->assertSame(2.0, (float) $scoped['units']);
        $this->assertArrayNotHasKey('gross_sales', $scoped);
        $this->assertSame(14000.0, $scoped['net_sales']);
        $this->assertSame(44000.0, $scoped['total']);       // Total tetap utuh (pembayaran tak dibagi per penjual)
    }

    public function test_a_user_without_the_sales_menu_cannot_export_references_or_proofs(): void
    {
        $order = $this->makeOrder();
        $this->addReference($order, [], 'rahasia');
        $noSales = User::factory()->create(['role' => 'cashier']);
        $noSales->update(['role_id' => \App\Models\Role::factory()->create(['menu_keys' => ['dashboard']])->id]);
        $this->assertFalse($noSales->fresh()->canAccessMenu('sales'), 'prasyarat: peran khusus tanpa menu sales');

        $this->actingAs($noSales->fresh(), 'sanctum');
        $this->postJson(self::URL, ['event_id' => $this->event->id])->assertStatus(403);
    }

    public function test_it_requires_a_login(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson(self::URL, [])->assertStatus(401);
    }
    // --- spec 062 (US2): jenis QR dan catatan pembayaran ---------------------------------------------

    /** Tambah satu pembayaran pada penjualan; non-tunai butuh channel (constraint DB chk_payments_channel). */
    private function addPayment(Order $order, string $method, ?string $provider = null, ?string $notes = null, string $verification = 'verified'): Payment
    {
        $channel = $provider === null ? null : PaymentChannel::create([
            'type' => $method === 'bank_transfer' ? 'bank_transfer' : 'qr_ewallet', 'provider' => $provider, 'account_name' => 'T', 'is_active' => true,
        ]);

        return Payment::create([
            'order_id' => $order->id, 'method' => $method, 'purpose' => 'settlement', 'amount' => 1000, 'verification' => $verification,
            'paid_at' => now(), 'channel_id' => $channel?->id, 'notes' => $notes, 'recorded_by' => $this->cashier->id,
        ]);
    }

    private function exportedRow(Order $order): array
    {
        return $this->exported(['keys' => ["order:{$order->id}"]])[0];
    }

    public function test_qr_type_names_the_provider_of_a_qr_payment(): void
    {
        $order = $this->makeOrder();
        $this->addPayment($order, 'qr_ewallet', 'GoPay');

        $this->assertSame('GoPay', $this->exportedRow($order)['qr_type']);
    }

    public function test_qr_type_lists_each_provider_once_for_several_qr_payments(): void
    {
        $order = $this->makeOrder();
        $this->addPayment($order, 'qr_ewallet', 'GoPay');
        $this->addPayment($order, 'qr_ewallet', 'Shopee');
        $this->addPayment($order, 'qr_ewallet', 'GoPay');

        $this->assertSame('GoPay, Shopee', $this->exportedRow($order)['qr_type']);
    }

    public function test_qr_type_is_empty_for_cash_and_bank_transfer_and_ignores_rejected_and_still_names_a_deleted_channel(): void
    {
        $cash = $this->makeOrder();
        $this->assertSame('', $this->exportedRow($cash)['qr_type']);

        $bank = $this->makeOrder();
        $this->addPayment($bank, 'bank_transfer', 'BCA');
        $this->assertSame('', $this->exportedRow($bank)['qr_type']);

        $rejected = $this->makeOrder();
        $this->addPayment($rejected, 'qr_ewallet', 'GoPay', null, 'rejected');
        $this->assertSame('', $this->exportedRow($rejected)['qr_type']);

        $deleted = $this->makeOrder();
        $payment = $this->addPayment($deleted, 'qr_ewallet', 'OVO');
        $payment->channel->delete(); // soft delete: penyedia tetap terbaca
        $this->assertSame('OVO', $this->exportedRow($deleted)['qr_type']);
    }

    public function test_payment_note_lists_one_line_per_payment_note_with_its_method(): void
    {
        $order = $this->makeOrder();
        $this->addPayment($order, 'qr_ewallet', 'GoPay', 'Transfer dari kakak');
        $this->addPayment($order, 'bank_transfer', 'BCA', 'Pelunasan sisa');

        $this->assertSame("qr_ewallet: Transfer dari kakak\nbank_transfer: Pelunasan sisa", $this->exportedRow($order)['payment_note']);
    }

    public function test_payment_note_is_empty_without_notes_and_ignores_rejected_payments(): void
    {
        $none = $this->makeOrder();
        $this->assertSame('', $this->exportedRow($none)['payment_note']);

        $rejected = $this->makeOrder();
        $this->addPayment($rejected, 'qr_ewallet', 'GoPay', 'Catatan yang ditolak', 'rejected');
        $this->assertSame('', $this->exportedRow($rejected)['payment_note']);
    }

    public function test_the_sale_reference_note_is_unchanged_and_separate_from_the_payment_note(): void
    {
        $order = $this->makeOrder();
        $this->addPayment($order, 'qr_ewallet', 'GoPay', 'Catatan pembayaran');
        $order->update(['reference_note' => 'Catatan penjualan', 'reference_updated_at' => now(), 'reference_updated_by' => $this->cashier->id]);

        $row = $this->exportedRow($order);

        $this->assertSame('Catatan penjualan', $row['reference_note']);
        $this->assertSame('qr_ewallet: Catatan pembayaran', $row['payment_note']);
    }
    // --- spec 062 (US4): total keseluruhan net sales ---------------------------------------------------

    /** Objek ekspor yang dibangun untuk permintaan ini (untuk memeriksa rows() dan grandTotalRow()). */
    private function exportObject(array $body = [], ?User $as = null): SalesTransactionsExport
    {
        Excel::fake();
        if ($as) {
            $this->actingAs($as, 'sanctum');
        }
        $this->postJson(self::URL, array_merge(['event_id' => $this->event->id], $body))->assertOk();
        $captured = null;
        Excel::assertExportedInRaw(SalesTransactionsExport::class, function (SalesTransactionsExport $export) use (&$captured) {
            $captured = $export;

            return true;
        });

        return $captured;
    }

    public function test_the_last_row_is_a_grand_total_of_net_sales(): void
    {
        $this->cashier->update(['language' => 'en']); // label mengikuti bahasa pengguna; pengguna uji default-nya Indonesia
        $this->makeOrder();
        $this->makeOrder();
        $this->makeOrder();

        $response = $this->postJson(self::URL, ['event_id' => $this->event->id])->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'sx').'.xlsx';
        file_put_contents($file, $response->getContent());
        $sheet = IOFactory::load($file)->getActiveSheet();
        @unlink($file);
        $rows = $sheet->toArray(null, true, true, false);
        $head = $rows[0];
        $last = $rows[count($rows) - 1];
        $netCol = array_search('net_sales', $head) + 1;

        $this->assertCount(5, $rows); // judul + 3 penjualan + total
        $this->assertSame('Grand total', $last[array_search('transaction_no', $head)]);
        // Tipe sel SUNGGUHAN: numerik (bisa dihitung lagi di Excel), bukan teks yang kebetulan berisi angka.
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC, $sheet->getCell([$netCol, 5])->getDataType());
        $this->assertEquals(90000, $sheet->getCell([$netCol, 5])->getValue());
        $this->assertTrue($sheet->getStyle([1, 5])->getFont()->getBold(), 'baris total ditebalkan');
        $this->assertEquals(90000, array_sum(array_map(fn ($r) => $r[array_search('net_sales', $head)], array_slice($rows, 1, 3))));
        foreach ($head as $i => $name) {
            if (! in_array($name, ['transaction_no', 'net_sales'], true)) {
                $this->assertTrue($last[$i] === null || $last[$i] === '', "sel '{$name}' pada baris total harus kosong");
            }
        }
    }

    public function test_the_grand_total_follows_a_seller_filter(): void
    {
        $second = Product::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Yuki'])->id, 'category_id' => Category::factory()->create()->id, 'name' => 'Poster'])
            ->variants()->create(['sku' => 'YUKI0099', 'sell_price' => 7000, 'cost_price' => 1000, 'current_stock' => 50]);
        $this->makeOrder(['items' => [['variant_id' => $this->variant->id, 'qty' => 3], ['variant_id' => $second->id, 'qty' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 44000]]]);
        $this->makeOrder(); // hanya Nekoyama: porsi Yuki = 0

        $export = $this->exportObject(['sellers' => ['Yuki']]);

        $this->assertEquals(14000, $export->grandTotalRow()['net_sales']);
        $this->assertEquals(14000, array_sum(array_column($export->rows(), 'net_sales')));
    }

    public function test_voided_sales_are_listed_when_asked_but_not_in_the_grand_total(): void
    {
        $this->makeOrder();
        $voided = $this->makeOrder();
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("/api/v1/orders/{$voided->id}/void", ['reason' => 'Salah input'])->assertOk();

        $export = $this->exportObject(['include_voided' => true]);

        $this->assertCount(2, $export->rows());
        $this->assertContains('voided', array_column($export->rows(), 'status'));
        $this->assertEquals(30000, $export->grandTotalRow()['net_sales']);
    }

    public function test_no_grand_total_row_when_there_are_no_sales(): void
    {
        [$rows] = $this->realWorkbook();

        $this->assertStringNotContainsString('Grand total', json_encode($rows));
        $this->assertStringNotContainsString('Total keseluruhan', json_encode($rows));
        $this->assertNull($this->exportObject()->grandTotalRow());
    }

    public function test_rows_stays_sale_rows_only_and_exposes_the_total_separately(): void
    {
        $this->cashier->update(['language' => 'en']);
        $this->makeOrder();
        $this->makeOrder();

        $export = $this->exportObject();

        $this->assertCount(2, $export->rows());
        $this->assertSame('Grand total', $export->grandTotalRow()['transaction_no']);
        $this->assertEquals(60000, $export->grandTotalRow()['net_sales']);
    }

    public function test_the_grand_total_label_is_localised(): void
    {
        $this->makeOrder();
        $this->owner->update(['language' => 'id']);

        [$rows] = $this->realWorkbook([], $this->owner);

        $this->assertSame('Total keseluruhan', $rows[count($rows) - 1][0]);
    }
    public function test_the_columns_are_exactly_the_revised_list(): void
    {
        $order = $this->makeOrder();

        [$rows] = $this->realWorkbook(['keys' => ["order:{$order->id}"]], $this->owner);

        $this->assertSame(
            ['transaction_no', 'status', 'time', 'sellers', 'cashier', 'items', 'units', 'net_sales', 'payment_methods', 'qr_type', 'payment_status', 'verification', 'cash', 'non_cash', 'total', 'balance', 'payment_note', 'reference_note', 'payment_proofs'],
            array_values(array_filter($rows[0], fn ($h) => $h !== null && ! str_ends_with((string) $h, '_image_1') && ! preg_match('/_image_\d+$/', (string) $h))),
        );
    }
}
