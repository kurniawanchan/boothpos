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
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\AppName;
use App\Support\StoreIdentity;
use App\Support\PaymentSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // 028 — daftar order adalah daftar PENJUALAN: tab terbuka (belum
        // dibayar) punya endpoint sendiri (/tabs) dan tidak boleh bocor ke
        // sini, bahkan lewat ?status=open — konsumen mana pun yang lupa
        // memfilter status akan menampilkan tab belum-dibayar sebagai
        // transaksi (research.md Keputusan 5, baris 4).
        $orders = Order::query()
            ->whereIn('status', [Order::STATUS_COMPLETED, Order::STATUS_VOIDED])
            ->when($request->filled('session_id'), fn ($q) => $q->where('session_id', $request->integer('session_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->with('device:id,name')
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
        } catch (\App\Exceptions\InputRejectedException $e) {
            // 084 — pilihan modifier di luar aturan adalah masukan yang ditolak (422 per-field, sama dengan tab),
            // bukan konflik aturan bisnis; harus dilempar ulang SEBELUM catch ValidationException di bawah.
            throw $e;
        } catch (ValidationException $e) {
            // Aturan bisnis (stok tidak cukup, pembayaran kurang) dipetakan
            // ke 409 sesuai kontrak, bukan 422 generik — 422 dipakai untuk
            // kesalahan bentuk data, 409 untuk konflik aturan bisnis.
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }

        // 087 — `material_warnings` hanya muncul bila ada bahan yang menjadi minus (aditif; APK lama mengabaikan).
        return response()->json(app(\App\Support\MaterialWarningBag::class)->attachTo(new OrderResource($order)), 201);
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
            'items.variant.product.category', 'items.homeOutlet',
            'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'outlet:id,code,name', 'device:id,name',
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
            'items.variant.product.category', 'items.homeOutlet', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'outlet:id,code,name', 'device:id,name',
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
            'items.variant.product.category', 'items.homeOutlet', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'outlet:id,code,name', 'device:id,name',
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
            'items.variant.product.category', 'items.homeOutlet', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'outlet:id,code,name', 'device:id,name',
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
            'items.variant.product.category', 'items.homeOutlet', 'payments.channel', 'payments.recorder', 'payments.proofs', 'payments.verifier', 'customer', 'cashier', 'outlet:id,code,name', 'device:id,name',
        ])));
    }

    public function void(Request $request, Order $order): JsonResponse
    {
        // Sama seperti gerbang per-aksi lain — "batalkan
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
    public function receipt(Order $order): JsonResponse
    {
        // 028 — struk untuk tab yang belum dibayar akan tampil seperti
        // struk lunas dengan ringkasan pembayaran kosong.
        if ($order->status === Order::STATUS_OPEN) {
            return response()->json(['message' => __('orders_payments.receipt_unavailable_for_open_tab')], 409);
        }

        // 079 — kolom identitas outlet ikut dimuat: struk memuat nama, alamat, telepon, surel, dan catatan OUTLET transaksi.
        $order->load(['items.homeOutlet', 'payments', 'cashier', 'customer', 'table', 'splitFrom', 'outlet:id,code,name,address,contact_phone,contact_email,notes', 'device:id,name']);
        $summary = PaymentSummary::for($order);
        $store = StoreIdentity::forReceipt($order->outlet);

        return response()->json([
            // 079 — identitas toko (nama, alamat, telepon, surel, catatan) mengikuti OUTLET TEMPAT PENJUALAN, bukan
            // outlet yang sedang dipilih penonton, dan satu-satunya penentunya StoreIdentity (aturan hilangkan-bila-kosong
            // dan cadangan blok bersama ada di sana). Nama toko DEMO/LIVE (003) tetap dihormati di blok bersama itu.
            // Kunci lama dipertahankan agar web dan tablet yang belum diperbarui tetap terbaca: `store_contact` = telepon.
            'store_name' => $store['name'] ?? 'Toko',
            'store_contact' => $store['contact'],
            'store_address' => $store['address'],
            'store_notes' => $store['notes'],
            // 006-purchase-order-and-ops (US7) — receipt_show_logo TIDAK
            // mengubah apakah logo ditampilkan di layar LAIN (mis. header
            // app) — hanya di STRUK, sesuai FR-017/User Story 7.
            'store_logo_url' => filter_var(Setting::get('receipt_show_logo', true), FILTER_VALIDATE_BOOLEAN)
                ? $this->imageUploadService->url(Setting::get('store_logo_path'))
                : null,
            'store_contact_person' => Setting::get('store_contact_person'),
            'store_contact_phone' => $store['phone'],
            'store_contact_email' => $store['email'],
            'receipt_footer_text' => Setting::get('receipt_footer_text'),
            'app_name' => AppName::current(),
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
            // 078 — catatan kasir untuk SELURUH order (`orders.notes`), dicetak di struk di bawah item. Catatan per baris tetap di `items[].note`.
            'notes' => $order->notes,
            // 048 — nomor struk, nomor antrean counter, jumlah tamu, dan pajak eksklusif.
            // 064 — nomor struk urut polos per outlet (`000124`; nomor lama `T01-000124` tetap); terminal yang mengambil
            // order tampil lewat NAMA (null untuk order dari SPA web / terminal yang sudah dihapus).
            'receipt_no' => $order->receipt_no,
            'terminal_name' => $order->device?->name,
            'queue_no' => $order->queue_no,
            'guest_count' => $order->guest_count,
            'tax_amount' => number_format((float) $order->tax_amount, 2, '.', ''),
            'tax_rate' => number_format((float) $order->tax_rate_snapshot, 2, '.', ''),
            'tax_label' => \App\Support\PosSettings::taxLabel(),
            // 083 — klien menyembunyikan BARIS pajak bila false; tax_amount dan total_amount tetap apa adanya.
            'tax_show' => \App\Support\PosSettings::taxShow(),
            // 028 — struk penjualan dari tab menampilkan meja/label takeaway.
            'service_type' => $order->service_type,
            'table' => $order->table ? ['id' => $order->table->id, 'name' => $order->table->name, 'area' => $order->table->area] : null,
            'tab_label' => $order->tab_label,
            // 028 — penjualan hasil pisah tagihan menyebut nomor tab asalnya.
            'split_from_order_number' => $order->splitFrom?->order_number,
            // 045: outlet tempat penjualan terjadi (outlet shift). Hanya dicetak bila instalasi punya lebih dari satu outlet.
            'outlet_name' => $order->outlet?->name,
            'outlet_code' => $order->outlet?->code,
            'cashier_name' => $order->cashier->name,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name_snapshot, 'qty' => $i->qty,
                'price' => number_format((float) $i->sell_price, 2, '.', ''),
                'line_total' => number_format((float) $i->line_total, 2, '.', ''),
                // 028 — pilihan modifier & catatan dicetak di struk (kosong untuk penjualan ritel).
                'modifiers' => \App\Support\ModifierSnapshot::forDisplay($i->modifiers),
                'note' => $i->note,
                // 003-seed-demo-live follow-up (FR-019) — satu transaksi
                // booth multi-artist bisa berisi barang dari beberapa
                // artist sekaligus; nama artist per baris, bukan satu
                // nama tunggal di kop struk. artist_snapshot tidak ada
                // (hanya home_outlet_id disimpan di order_items), jadi diambil
                // dari relasi — artist tidak pernah dihapus keras
                // (RESTRICT di FK), jadi selalu ada.
                'home_outlet_name' => $i->homeOutlet?->name,
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
        ]);
    }
}
