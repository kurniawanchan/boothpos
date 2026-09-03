<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Setting;
use App\Services\ImageUploadService;
use App\Services\OrderService;
use App\Support\ModeGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
        private ImageUploadService $imageUploadService,
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
        return response()->json(new OrderResource($order->load(['items', 'payments'])));
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
    public function receipt(Order $order): JsonResponse
    {
        $order->load(['items.artist', 'payments', 'cashier', 'event', 'customer']);

        return response()->json([
            // 003-seed-demo-live follow-up — nama toko DEMO dan LIVE
            // disimpan sebagai baris settings terpisah (lihat catatan di
            // SakanaFridgeDemoSeeder), supaya menjalankan seeder tidak
            // pernah menimpa nama toko sungguhan. Struk selalu memakai
            // nama milik MODE ORDER INI (bukan mode aktif saat ini) —
            // tapi karena Order sendiri sudah disaring DataModeScope,
            // ModeGate::current() saat request ini pasti sama dengan
            // data_mode order yang berhasil dimuat.
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
            ]),
            'change_amount' => number_format((float) $order->change_amount, 2, '.', ''),
        ]);
    }
}
