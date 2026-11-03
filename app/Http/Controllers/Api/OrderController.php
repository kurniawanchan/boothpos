<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentConfirmationRequest;
use App\Http\Requests\VerifyOrderPaymentsRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\ImageUploadService;
use App\Services\OrderReferenceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\AppName;
use App\Support\ModeGate;
use App\Support\PaymentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private ImageUploadService $imageUploadService,
        private PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $orders = Order::query()
            ->when($request->filled('event_id'), fn ($q) => $q->where('event_id', $request->integer('event_id')))
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->integer('session_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(), 'per_page' => $orders->perPage(),
                'total' => $orders->total(), 'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->create($request->validated(), $request->user());
        } catch (ValidationException $e) {
            // Aturan bisnis (stok tidak cukup, pembayaran kurang) dipetakan
            // ke 409 sesuai kontrak, bukan 422 generik — 422 dipakai untuk
            // kesalahan bentuk data, 409 untuk konflik aturan bisnis.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new OrderResource($order), 201);
    }

    public function show(Order $order): JsonResponse
    {
        // items.variant dimuat agar OrderResource bisa menyertakan product_id
        // per item — dibutuhkan oleh popup "Produk Terjual" di halaman Sales
        // (009-ui-ux-refinements US2) supaya klik nama produk bisa membuka
        // ProductDetailModal tanpa endpoint baru.
        // Relasi tambahan untuk tampilan detail transaksi di halaman Sales
        // (siapa, di mana, produk apa dari penjual mana, dibayar bagaimana).
        // Semuanya opt-in lewat whenLoaded() di OrderResource, jadi endpoint
        // lain yang memakai resource ini tidak berubah bentuk.
        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist',
            'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'event',
        ])));
    }

    /**
     * 028-partial-split-payment (US5) — pembayaran SUSULAN atas penjualan POS yang
     * baru dibayar sebagian. Semua aturan (sisa tagihan, idempotensi `client_ref`,
     * shift kasir untuk tunai, order batal = tertutup) ada di PaymentService;
     * di sini hanya memetakan ValidationException ke kode statusnya. 201 = baru,
     * 200 = replay `client_ref`.
     */
    public function storePayment(StorePaymentRequest $request, Order $order): JsonResponse
    {
        try {
            $order = $this->paymentService->addPayment($order, $request->validated(), $request->user(), $replayed);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'event',
        ])), $replayed ? 200 : 201);
    }

    /**
     * Hapus satu pembayaran penjualan POS — hanya owner/admin (menghapus catatan
     * uang). 409 untuk order batal dan untuk uang tunai milik shift yang sudah
     * ditutup (sudah direkonsiliasi); 404 bila bukan pembayaran order ini.
     */
    public function destroyPayment(Request $request, Order $order, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->isOwnerOrAdmin(), 403, __('orders_payments.not_authorized_void'));

        try {
            $order = $this->paymentService->deletePayment($order, $payment, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'event',
        ])));
    }

    /**
     * 032-mark-payment-verified (US3) — verifikasi massal dari daftar Sales. Hanya id penjualan yang
     * dikirim klien; semua aturan per pembayaran ada di PaymentService::verifyOrderPayments() (yang
     * tak bisa diverifikasi pemanggil dilewati dan dihitung per alasan, bukan 4xx). 422 hanya untuk
     * bentuk permintaan yang salah.
     */
    public function verifyPayments(VerifyOrderPaymentsRequest $request): JsonResponse
    {
        return response()->json($this->paymentService->verifyOrderPayments($request->validated('order_ids'), $request->user()));
    }

    /**
     * 032-mark-payment-verified — tandai SATU pembayaran non-tunai terverifikasi. Boleh: owner/admin
     * atau pengguna mana pun KECUALI pencatat pembayaran itu (403) — dijaga di sini DAN diulang di
     * PaymentService. Satu arah dan final: tidak ada route pembatalan. Aturan lain (batal 409, tunai
     * 422, sudah terverifikasi/ditolak 409) ada di service; di sini hanya memetakan ValidationException
     * ke kode statusnya, sama seperti destroyPayment().
     */
    public function verifyPayment(Request $request, Order $order, Payment $payment): JsonResponse
    {
        abort_unless($payment->mayVerify($request->user()), 403, __('orders_payments.payment_verify_not_allowed'));

        try {
            $order = $this->paymentService->markVerified($order, $payment, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'event',
        ])));
    }

    /**
     * 031-optional-payment-proof — tambah / ubah / ganti konfirmasi (bukti, referensi,
     * catatan) satu pembayaran non-tunai. Hanya owner/admin atau PENCATAT pembayaran itu
     * (403 untuk yang lain — dijaga di sini DAN diulang di PaymentService). Semua aturan
     * lain (transaksi batal 409, tunai 422, hasil tak boleh kosong 422, bukti lama
     * di-supersede bukan dihapus) ada di service; di sini hanya memetakan
     * ValidationException ke kode statusnya, sama seperti destroyPayment().
     */
    public function updatePaymentConfirmation(UpdatePaymentConfirmationRequest $request, Order $order, Payment $payment): JsonResponse
    {
        abort_unless($payment->mayManageConfirmation($request->user()), 403, __('orders_payments.payment_confirmation_not_allowed'));

        try {
            $order = $this->paymentService->updateConfirmation($order, $payment, $request->validated(), $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'event',
        ])));
    }

    // --- 054 (US8) referensi transaksi -----------------------------------------------------------
    // Membaca (catatan + gambar) = siapa pun yang punya menu 'sales' (halaman itu terbuka untuk semua peran
    // dan setiap kasir memang melihat semua penjualan); mengubah/menghapus = owner/admin atau kasir penjualannya.

    private function referenceBlock(Order $order, Request $request): array
    {
        $order->loadMissing('referenceEditor');

        return [
            'note' => $order->reference_note,
            'has_image' => $order->reference_image_path !== null,
            'updated_at' => $order->reference_updated_at,
            'updated_by_name' => $order->referenceEditor?->name,
            'can_edit' => OrderReferenceService::mayEdit($order, $request->user()),
        ];
    }

    public function showReference(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('sales'), 403, __('orders_payments.reference_not_allowed_view'));

        return response()->json($this->referenceBlock($order, $request));
    }

    public function referenceImage(Request $request, Order $order): Response|JsonResponse
    {
        abort_unless($request->user()->canAccessMenu('sales'), 403, __('orders_payments.reference_not_allowed_view'));

        $path = $order->reference_image_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => __('orders_payments.reference_image_missing')], 404);
        }

        // Diautorisasi per permintaan lalu dilayani dari disk privat; tak pernah di-cache bersama.
        return response(Storage::disk('local')->get($path))
            ->header('Content-Type', $order->reference_image_mime ?: 'application/octet-stream')
            ->header('Cache-Control', 'private, no-store');
    }

    public function saveReference(\App\Http\Requests\UpsertOrderReferenceRequest $request, Order $order, OrderReferenceService $service): JsonResponse
    {
        try {
            $order = $service->save($order, $request->safe()->only(['note', 'remove_image']), $request->file('image'), $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json($this->referenceBlock($order, $request));
    }

    public function deleteReference(Request $request, Order $order, OrderReferenceService $service): JsonResponse
    {
        try {
            $order = $service->delete($order, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json($this->referenceBlock($order, $request));
    }

    /** 054 (US7) — koreksi metode/kanal satu pembayaran (nominal tak pernah berubah). */
    public function changePaymentMethod(\App\Http\Requests\ChangePaymentMethodRequest $request, Order $order, Payment $payment): JsonResponse
    {
        abort_unless($payment->mayManageConfirmation($request->user()), 403, __('orders_payments.payment_method_change_not_allowed'));

        try {
            $order = $this->paymentService->changeMethod($order, $payment, $request->validated(), $request->user());
        } catch (\App\Exceptions\PaymentMethodChangeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status);
        }

        return response()->json(new OrderResource($order->load([
            'items.variant.product.category', 'items.artist', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'payments.session', 'customer', 'cashier', 'event',
        ])));
    }

    public function void(Request $request, Order $order): JsonResponse
    {
        // Sama seperti catatan di EventPolicy::create() — "batalkan
        // transaksi" adalah gerbang per-aksi di dalam menu 'sales'/'pos'
        // yang dibagi semua peran, bukan aksesnya sendiri. Dipetakan ke
        // canAccessMenu('settings') untuk alasan yang sama (satu-satunya
        // kunci menu yang, pada keempat peran default, persis berisi
        // owner+admin).
        if (! $request->user()->canAccessMenu('settings')) {
            return response()->json(['message' => __('orders_payments.not_authorized_void')], 403);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $order = $this->orderService->void($order, $validated['reason'], $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        return response()->json(new OrderResource($order));
    }

    // 002-language-toggle FR-009 — SENGAJA tidak memakai __() di sini
    // meski SetLocaleFromUser tetap aktif untuk route ini. Struk dibaca
    // PELANGGAN, bukan operator toko, jadi harus selalu Bahasa Indonesia
    // terlepas dari preferensi bahasa kasir yang sedang login. Jangan
    // "perbaiki" ini jadi ikut ter-i18n-kan.
    /**
     * 054 (US9) — identitas toko pada struk (nama, kontak, alamat, logo, footer, nama aplikasi). Sama untuk semua struk
     * dalam satu permintaan, jadi dibangun SEKALI: membacanya per struk berarti ~8 query pengaturan per penjualan
     * (88 query untuk 11 struk), yang membuat unduh massal 200 struk sangat boros.
     */
    private function receiptStoreBlock(): array
    {
        return [
            'store_name' => Setting::get(ModeGate::isDemo() ? 'store_name_demo' : 'store_name', 'Toko'),
            'store_contact' => Setting::get('store_contact'),
            // 001-user-store-settings User Story 3 / SC-004 — struk harus
            // menampilkan identitas toko LENGKAP sesuai yang dikonfigurasi
            // di Pengaturan, bukan cuma nama+satu kontak seperti
            // sebelumnya. Field ini pernah terlewat dari task breakdown
            // awal fitur tsb — ditemukan saat implementasi profil toko,
            // ditutup di sini.
            'store_address' => Setting::get('store_address'),
            // 006-purchase-order-and-ops (US7) — receipt_show_logo TIDAK
            // mengubah apakah logo ditampilkan di layar LAIN (mis. header
            // app) — hanya di STRUK, sesuai FR-017/User Story 7.
            'store_logo_url' => filter_var(Setting::get('receipt_show_logo', true), FILTER_VALIDATE_BOOLEAN)
                ? $this->imageUploadService->url(Setting::get('store_logo_path'))
                : null,
            'store_contact_person' => Setting::get('store_contact_person'),
            'store_contact_phone' => Setting::get('store_contact_phone'),
            'store_contact_email' => Setting::get('store_contact_email'),
            'receipt_footer_text' => Setting::get('receipt_footer_text'),
            'app_name' => AppName::current(),
        ];
    }

    /**
     * 054 (US9) — satu-satunya perakit isi struk, dipakai GET /orders/{id}/receipt DAN unduh massal
     * (POST /orders/bulk-receipts), supaya struk massal tak pernah berbeda dari struk di layar.
     */
    private function receiptPayload(Order $order, ?array $store = null): array
    {
        $store ??= $this->receiptStoreBlock();
        $order->loadMissing(['items.artist', 'payments', 'cashier', 'event', 'customer']);
        // Entri yang sudah dimuat diteruskan: tanpa itu PaymentSummary menanyakan pembayaran SATU PER penjualan (N+1 di unduh massal).
        $summary = PaymentSummary::for($order, $order->payments);

        return [
            // 054 — status ikut dikirim supaya struk penjualan batal bisa diberi tanda (unduh massal memuatnya bila daftar menampilkannya).
            'status' => $order->status,
            // 003-seed-demo-live follow-up — nama toko DEMO dan LIVE
            // disimpan sebagai baris settings terpisah (lihat catatan di
            // SakanaFridgeDemoSeeder), supaya menjalankan seeder tidak
            // pernah menimpa nama toko sungguhan. Struk selalu memakai
            // nama milik MODE ORDER INI (bukan mode aktif saat ini) —
            // tapi karena Order sendiri sudah disaring DataModeScope,
            // ModeGate::current() saat request ini pasti sama dengan
            // data_mode order yang berhasil dimuat.
            ...$store,
            // 003-seed-demo-live follow-up 2 (FR-024) — BUG YANG DITEMUKAN
            // & DIPERBAIKI: footer struk sebelumnya HANYA menampilkan
            // store_contact_person/phone/email (kontak TOKO, bukan
            // pembeli), yang di database dev kebetulan berisi data contoh
            // ("Budi Santoso" dkk. dari test fixture) sehingga terlihat
            // seperti data palsu di struk pembeli. Struk pembeli lebih
            // masuk akal menampilkan PEMBELI transaksi itu sendiri; null
            // semua untuk order walk-in (customer_id kosong), apa adanya.
            'customer_name' => $order->customer?->name,
            'customer_phone' => $order->customer?->phone,
            'customer_email' => $order->customer?->email,
            'order_number' => $order->order_number,
            'event_name' => $order->event->name,
            // 014-sales-receipt-event-footer (US2, R2) — event Order tidak
            // pernah null (beda dengan Preorder), tapi kolom tanggalnya
            // sendiri masih bisa null, jadi tetap pakai ?->toDateString().
            'event_location' => $order->event->location,
            'event_start_date' => $order->event->start_date?->toDateString(),
            'event_end_date' => $order->event->end_date?->toDateString(),
            // 023-event-availability-invoice-redesign (US2) — sama persis
            // dengan PreorderController::invoicePayload(); Order SELALU
            // punya event (beda dari Preorder yang opsional), jadi tidak
            // perlu null-safe di sisi event-nya sendiri.
            'event_available_on_date' => $order->event->availableOnDate()?->toDateString(),
            // Pilihan mentah dikirim juga (bukan cuma tanggal yang sudah
            // diselesaikan) supaya frontend bisa membedakan 'both' (tampil
            // sebagai rentang event_start_date..event_end_date) dari tidak
            // ada batasan sama sekali (available_on null) — dua-duanya
            // sama-sama membuat availableOnDate() null.
            'event_available_on' => $order->event->available_on,
            'cashier_name' => $order->cashier->name,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name_snapshot, 'qty' => $i->qty,
                'price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
                // 003-seed-demo-live follow-up (FR-019) — satu transaksi
                // booth multi-artist bisa berisi barang dari beberapa
                // artist sekaligus; nama artist per baris, bukan satu
                // nama tunggal di kop struk. artist_snapshot tidak ada
                // (hanya artist_id disimpan di order_items), jadi diambil
                // dari relasi — artist tidak pernah dihapus keras
                // (RESTRICT di FK), jadi selalu ada.
                'artist_name' => $i->artist?->name,
            ]),
            'subtotal' => number_format((float) $order->subtotal, 2, '.', ''),
            'discount_amount' => number_format((float) $order->discount_amount, 2, '.', ''),
            'total_amount' => number_format((float) $order->total_amount, 2, '.', ''),
            'payment_summary' => $order->payments->map(fn ($p) => [
                'method' => $p->method, 'amount' => number_format((float) $p->amount, 2, '.', ''),
                // 028 — struk yang dicetak ulang setelah pembayaran susulan memuat entri baru.
                'reference' => $p->reference, 'paid_at' => $p->paid_at,
            ]),
            'change_amount' => number_format((float) $order->change_amount, 2, '.', ''),
            // 028 — jumlah terbayar, sisa tagihan, dan status untuk struk penjualan sebagian.
            'paid_amount' => $summary['total_paid'],
            'balance_amount' => $summary['remaining'],
            'payment_status' => $summary['status'],
        ];
    }
    public function receipt(Order $order): JsonResponse
    {
        return response()->json($this->receiptPayload($order));
    }

    /**
     * 054 (US9) — isi struk banyak penjualan sekaligus untuk unduhan massal (PDF dirender di browser, sama
     * seperti struk satuan). Dibatasi 200 id; id yang tak ditemukan pada mode data aktif (atau sudah hilang)
     * dilaporkan di `missing`, bukan dikarang. Eager-load SEKALI untuk semua penjualan (tanpa N+1).
     */
    public function bulkReceipts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'order_ids.*' => ['integer'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['order_ids'])));

        $orders = Order::with(['items.artist', 'payments', 'cashier', 'event', 'customer'])->whereIn('id', $ids)->get()->keyBy('id');
        $store = $this->receiptStoreBlock();

        return response()->json([
            // Urutan mengikuti permintaan (urutan daftar di layar).
            'data' => collect($ids)->filter(fn ($id) => $orders->has($id))->map(fn ($id) => $this->receiptPayload($orders[$id], $store))->values(),
            'missing' => collect($ids)->reject(fn ($id) => $orders->has($id))->values(),
        ]);
    }

}
