<?php

namespace App\Services\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\CustomerOrderShipment;
use App\Models\CustomerOrderStatusEvent;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\StockService;
use App\Support\ReportSplit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Customer commerce (CONTRACT §9) — SATU-satunya penulis status pesanan
 * pelanggan (`customer_orders.status`), riwayatnya
 * (`customer_order_status_events`) dan pengirimannya
 * (`customer_order_shipments`). Tidak ada controller, service lain, atau
 * model yang boleh menulis ketiganya; pelanggan sendiri tidak punya jalur
 * untuk mengubah status.
 *
 * Kenapa satu pintu: riwayat status adalah kebenaran yang dilihat pelanggan
 * dan hanya-tambah. Kalau status bisa berubah tanpa baris riwayat (atau
 * sebaliknya), linimasa di aplikasi berbohong dan tidak bisa diperbaiki.
 *
 * Setiap method tulis mengikuti urutan yang sama, dalam SATU transaksi:
 * kunci baris pesanan (lockForUpdate) → periksa tabel transisi terhadap
 * status TERKUNCI → tulis (status, stok, pengiriman) → tambah peristiwa →
 * ActivityLogger. Karena statusnya dibaca setelah dikunci, dua permintaan
 * bersamaan untuk pesanan yang sama tidak bisa sama-sama lolos: yang kedua
 * melihat status baru dan ditolak 409 `invalid_transition` — itulah yang
 * membuat stok dikurangi/dikembalikan TEPAT satu kali.
 *
 * Stok: tidak bergerak saat checkout. Dikurangi saat KONFIRMASI lewat
 * StockService::applyMovement (jenis `sale`), dikembalikan saat BATAL bila
 * `stock_deducted_at` terisi (jenis `return`). Referensi keduanya adalah id
 * ITEM pesanan (`customer_order_item`). `current_stock` tidak pernah ditulis
 * langsung dari sini.
 *
 * Pelanggaran aturan dilempar sebagai CustomerCommerceException 409 dengan
 * kode mesin (`invalid_transition`, `wrong_fulfilment_method`,
 * `insufficient_stock`, `variant_unavailable`); di rute staf ia dirender
 * `{message, code}`. Pesanan selesai sebagai catatannya sendiri: TIDAK ada
 * penjualan POS / pre-order yang dibuat dan laporan tidak tersentuh
 * (pertanyaan terbuka nomor 6 di design.md masih terbuka).
 */
class CustomerOrderTransitionService
{
    /** Status pesanan yang baru dibuat; tidak ada transisi yang MENUJU status ini. */
    public const INITIAL_STATUS = CustomerOrder::STATUS_AWAITING_CONFIRMATION;

    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_PACK = 'pack';

    public const ACTION_READY = 'ready';

    public const ACTION_PICKUP = 'pickup';

    public const ACTION_SHIP = 'ship';

    public const ACTION_DELIVER = 'deliver';

    public const ACTION_CANCEL = 'cancel';

    public const ERROR_INVALID_TRANSITION = 'invalid_transition';

    public const ERROR_WRONG_FULFILMENT_METHOD = 'wrong_fulfilment_method';

    public const ERROR_INSUFFICIENT_STOCK = 'insufficient_stock';

    public const ERROR_VARIANT_UNAVAILABLE = 'variant_unavailable';

    /** `stock_movements.reference_type` untuk konfirmasi (sale) dan pembatalan (return); reference_id = id item pesanan. */
    public const STOCK_REFERENCE = 'customer_order_item';

    /**
     * Percobaan transaksi yang menyentuh baris varian (konfirmasi, pembatalan, checkout).
     *
     * Kunci baris varian di sini SELALU menaik menurut id, tetapi penjualan POS
     * (OrderService) mengunci varian menurut urutan baris keranjang kasir, jadi dua
     * transaksi yang menyentuh varian yang sama bisa saling menunggu (deadlock). MySQL
     * membatalkan salah satunya; `DB::transaction($callback, N)` mengulang closure-nya
     * HANYA pada deadlock (galat lain, termasuk ValidationException dan
     * CustomerCommerceException, langsung dilempar ulang tanpa percobaan ulang). Tanpa itu,
     * korbannya menjadi 500 padahal mengulang transaksinya hampir pasti berhasil. Closure
     * yang dibungkus HARUS aman diulang: tidak ada state di luar closure yang diubah.
     */
    public const DEADLOCK_ATTEMPTS = 3;

    /**
     * Aksi staf => status asal yang sah, status tujuan, dan metode pemenuhan
     * yang disyaratkan (null = keduanya). Nama aksi sama dengan aksi bernama
     * menu `customer_orders`.
     *
     *   awaiting_confirmation → confirmed → packing → ready_for_pickup → picked_up   (ambil di tempat)
     *                                          └────→ shipped → delivered            (kurir)
     *
     * Pembatalan hanya sebelum barang keluar. `shipped`, `delivered` dan
     * `picked_up` tidak bisa dibatalkan: belum ada alur retur di v1
     * (kebijakan yang didokumentasikan, bukan kelalaian).
     *
     * @var array<string, array{from: list<string>, to: string, fulfilment: ?string}>
     */
    public const TRANSITIONS = [
        self::ACTION_CONFIRM => [
            'from' => [CustomerOrder::STATUS_AWAITING_CONFIRMATION],
            'to' => CustomerOrder::STATUS_CONFIRMED,
            'fulfilment' => null,
        ],
        self::ACTION_PACK => [
            'from' => [CustomerOrder::STATUS_CONFIRMED],
            'to' => CustomerOrder::STATUS_PACKING,
            'fulfilment' => null,
        ],
        self::ACTION_READY => [
            'from' => [CustomerOrder::STATUS_PACKING],
            'to' => CustomerOrder::STATUS_READY_FOR_PICKUP,
            'fulfilment' => CustomerOrder::FULFILMENT_PICKUP,
        ],
        self::ACTION_PICKUP => [
            'from' => [CustomerOrder::STATUS_READY_FOR_PICKUP],
            'to' => CustomerOrder::STATUS_PICKED_UP,
            'fulfilment' => CustomerOrder::FULFILMENT_PICKUP,
        ],
        self::ACTION_SHIP => [
            'from' => [CustomerOrder::STATUS_PACKING],
            'to' => CustomerOrder::STATUS_SHIPPED,
            'fulfilment' => CustomerOrder::FULFILMENT_COURIER,
        ],
        self::ACTION_DELIVER => [
            'from' => [CustomerOrder::STATUS_SHIPPED],
            'to' => CustomerOrder::STATUS_DELIVERED,
            'fulfilment' => CustomerOrder::FULFILMENT_COURIER,
        ],
        self::ACTION_CANCEL => [
            'from' => [
                CustomerOrder::STATUS_AWAITING_CONFIRMATION,
                CustomerOrder::STATUS_CONFIRMED,
                CustomerOrder::STATUS_PACKING,
                CustomerOrder::STATUS_READY_FOR_PICKUP,
            ],
            'to' => CustomerOrder::STATUS_CANCELLED,
            'fulfilment' => null,
        ],
    ];

    /** Status akhir: tidak ada transisi yang keluar dari sini. */
    public const TERMINAL_STATUSES = [
        CustomerOrder::STATUS_DELIVERED,
        CustomerOrder::STATUS_PICKED_UP,
        CustomerOrder::STATUS_CANCELLED,
    ];

    public function __construct(
        private ActivityLogger $activityLogger,
        private StockService $stock,
    ) {}

    /**
     * Keputusan transisi sebagai fungsi MURNI (tanpa basis data, tanpa
     * terjemahan) supaya seluruh kombinasi (status, aksi, metode) bisa diuji
     * sebagai tabel. Tepat satu dari `to` / `error` terisi.
     *
     * Metode diperiksa lebih dulu daripada status: aksi yang memang bukan
     * milik metode pesanan ini (mengirim pesanan ambil-di-tempat, menyerahkan
     * pesanan kurir di toko) SELALU `wrong_fulfilment_method`, apa pun
     * statusnya — jawabannya tidak berubah-ubah mengikuti status.
     *
     * @return array{to: ?string, error: ?string}
     *
     * @throws InvalidArgumentException aksi di luar tabel = salah ketik di kode, bukan konflik bisnis
     */
    public static function evaluate(string $action, string $status, string $fulfilmentMethod): array
    {
        $rule = self::TRANSITIONS[$action]
            ?? throw new InvalidArgumentException("Aksi transisi pesanan pelanggan tidak dikenal: {$action}.");

        if ($rule['fulfilment'] !== null && $rule['fulfilment'] !== $fulfilmentMethod) {
            return ['to' => null, 'error' => self::ERROR_WRONG_FULFILMENT_METHOD];
        }

        if (! in_array($status, $rule['from'], true)) {
            return ['to' => null, 'error' => self::ERROR_INVALID_TRANSITION];
        }

        return ['to' => $rule['to'], 'error' => null];
    }

    /**
     * Aksi yang sah untuk keadaan pesanan saat ini (untuk tombol di layar
     * staf). Hanya petunjuk tampilan: penegakannya tetap di tiap method tulis.
     *
     * @return list<string>
     */
    public static function availableActions(CustomerOrder $order): array
    {
        return array_values(array_filter(
            array_keys(self::TRANSITIONS),
            fn (string $action) => self::evaluate($action, $order->status, $order->fulfilment_method)['error'] === null,
        ));
    }

    /** Data pengiriman (resi/layanan) hanya bisa diperbarui selama pesanan kurir berstatus `shipped`. */
    public static function shipmentEditable(CustomerOrder $order): bool
    {
        return $order->isCourier() && $order->status === CustomerOrder::STATUS_SHIPPED;
    }

    /**
     * Peristiwa PERTAMA sebuah pesanan: "dibuat pelanggan, menunggu
     * konfirmasi". Dipanggil CustomerCheckoutService tepat setelah baris
     * pesanan dan itemnya ditulis, DI DALAM transaksi checkout yang sama —
     * supaya tidak pernah ada pesanan tanpa riwayat, atau riwayat/log untuk
     * pesanan yang batal dibuat.
     *
     * Stok TIDAK bergerak di sini (baru saat staf mengonfirmasi).
     *
     * ActivityLogger dipanggil dengan `userId: null`: pelakunya pelanggan,
     * dan `activity_logs.user_id` adalah FK ke `users`. Mengisi id akun
     * pelanggan di sana akan menuduh staf yang kebetulan ber-id sama.
     * Deskripsi dan nilainya tidak memuat nama, e-mail, telepon, alamat,
     * atau catatan pelanggan.
     */
    public function recordPlaced(CustomerOrder $order): CustomerOrderStatusEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('recordPlaced() harus dipanggil di dalam transaksi checkout.');
        }

        if (! $order->exists || $order->status !== self::INITIAL_STATUS || $order->statusEvents()->exists()) {
            throw new LogicException('recordPlaced() hanya untuk pesanan baru yang belum punya riwayat status.');
        }

        $event = $order->statusEvents()->create([
            'status' => self::INITIAL_STATUS,
            'event_type' => CustomerOrderStatusEvent::EVENT_STATUS_CHANGED,
            'note' => null,
            'actor_type' => CustomerOrderStatusEvent::ACTOR_CUSTOMER,
            'actor_user_id' => null,
            'metadata' => null,
            'occurred_at' => $order->created_at ?? now(),
        ]);

        $this->activityLogger->log(
            userId: null,
            action: 'customer_order_placed',
            entityType: 'CustomerOrder',
            entityId: $order->id,
            description: "Pesanan pelanggan {$order->order_number} dibuat lewat aplikasi.",
            newValues: [
                'status' => $order->status,
                'fulfilment_method' => $order->fulfilment_method,
                'total' => number_format((float) $order->total, 2, '.', ''),
            ],
        );

        return $event;
    }

    // ------------------------------------------------------------------
    // Transisi staf (CONTRACT §9)
    // ------------------------------------------------------------------

    /**
     * awaiting_confirmation → confirmed. Di sinilah stok BARU dikurangi.
     *
     * Dua pesanan yang sama-sama lolos checkout untuk unit terakhir adalah
     * keadaan yang sah (checkout tidak mencadangkan stok), jadi ketersediaan
     * diperiksa ULANG di sini di bawah kunci baris varian. Bila ada satu
     * baris saja yang kurang / variannya hilang, seluruh konfirmasi ditolak
     * 409 dan TIDAK ADA yang ditulis (transaksi dibatalkan).
     *
     * `shipping_cost` (hanya pesanan kurir, WAJIB; nol eksplisit = gratis): tidak
     * ada tarif otomatis di v1, toko menetapkannya saat mengonfirmasi. Ongkir dan
     * total diperbarui bersama dan dicatat sebagai peristiwa `shipping_cost_set`.
     *
     * @param  array{shipping_cost?: int|float|string|null, note?: string|null}  $input  sudah divalidasi
     *
     * @throws CustomerCommerceException 409 `invalid_transition` | `insufficient_stock` | `variant_unavailable`
     * @throws ValidationException 422 `shipping_cost`: diisi untuk pesanan ambil di tempat, atau kosong untuk pesanan kurir
     */
    public function confirm(CustomerOrder $order, User $staff, array $input = []): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $input) {
            $order = $this->lock($order);
            $from = $order->status;
            $to = $this->guard($order, self::ACTION_CONFIRM);

            $setsShippingCost = ($input['shipping_cost'] ?? null) !== null;

            if ($setsShippingCost && ! $order->isCourier()) {
                throw ValidationException::withMessages([
                    'shipping_cost' => __('customer_commerce.fulfilment.shipping_cost_courier_only'),
                ]);
            }

            // Tidak ada tarif otomatis dan tidak ada jalur mengubah ongkir sesudah konfirmasi, jadi pesanan
            // kurir TIDAK boleh dikonfirmasi tanpa ongkir (nol eksplisit = gratis). ConfirmCustomerOrderRequest
            // sudah menuntutnya; ini penjaga untuk pemanggil lain, terhadap baris yang terkunci.
            if (! $setsShippingCost && $order->isCourier()) {
                throw ValidationException::withMessages([
                    'shipping_cost' => __('customer_commerce.fulfilment.shipping_cost_required'),
                ]);
            }

            $this->deductStock($order, $staff);

            $now = now();
            $logValues = [];

            if ($setsShippingCost) {
                $oldCost = ReportSplit::cents($order->shipping_cost);
                $oldTotal = ReportSplit::cents($order->total);
                $newCost = ReportSplit::cents($input['shipping_cost']);
                // Total SELALU dihitung ulang dari subtotal, tidak pernah dari total lama + selisih.
                $newTotal = ReportSplit::cents($order->subtotal) + $newCost;

                $order->shipping_cost = ReportSplit::money($newCost);
                $order->total = ReportSplit::money($newTotal);

                // Ditulis SEBELUM peristiwa status supaya peristiwa teratas linimasa tetap "dikonfirmasi".
                $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_SHIPPING_COST_SET, $staff, null, [
                    'old_shipping_cost' => ReportSplit::money($oldCost),
                    'new_shipping_cost' => ReportSplit::money($newCost),
                    'old_total' => ReportSplit::money($oldTotal),
                    'new_total' => ReportSplit::money($newTotal),
                ], $now);

                $logValues = ['shipping_cost' => ReportSplit::money($newCost), 'total' => ReportSplit::money($newTotal)];
            }

            $order->status = $to;
            $order->confirmed_at = $now;
            $order->stock_deducted_at = $now;
            $order->save();

            $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $staff, $input['note'] ?? null, [
                'action' => self::ACTION_CONFIRM,
                'from' => $from,
                'stock_deducted' => true,
            ], $now);

            $this->audit($staff, self::ACTION_CONFIRM, $order, "Pesanan pelanggan {$order->order_number} dikonfirmasi; stok dikurangi.", $from, $logValues);

            return $order;
        }, self::DEADLOCK_ATTEMPTS); // mengunci baris varian: ulangi bila terkena deadlock (lihat DEADLOCK_ATTEMPTS)
    }

    /** confirmed → packing. @param array{note?: string|null} $input */
    public function pack(CustomerOrder $order, User $staff, array $input = []): CustomerOrder
    {
        return $this->move($order, $staff, self::ACTION_PACK, $input['note'] ?? null, 'mulai dikemas');
    }

    /** packing → ready_for_pickup (hanya pesanan ambil di tempat). @param array{note?: string|null} $input */
    public function ready(CustomerOrder $order, User $staff, array $input = []): CustomerOrder
    {
        return $this->move($order, $staff, self::ACTION_READY, $input['note'] ?? null, 'siap diambil');
    }

    /** ready_for_pickup → picked_up; pesanan selesai (`completed_at`). @param array{note?: string|null} $input */
    public function pickup(CustomerOrder $order, User $staff, array $input = []): CustomerOrder
    {
        return $this->move($order, $staff, self::ACTION_PICKUP, $input['note'] ?? null, 'sudah diambil', completes: true);
    }

    /**
     * packing → shipped (hanya pesanan kurir). Membuat SATU baris pengiriman;
     * `shipping_cost`-nya adalah snapshot ongkir pesanan saat dikirim.
     *
     * @param  array{courier_name: string, service_name?: string|null, tracking_number?: string|null, dispatched_at?: string|null, note?: string|null}  $input  sudah divalidasi (kurir dari Couriers::OPTIONS)
     */
    public function ship(CustomerOrder $order, User $staff, array $input): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $input) {
            $order = $this->lock($order);
            $from = $order->status;
            $to = $this->guard($order, self::ACTION_SHIP);

            // Indeks UNIQUE pada customer_order_id adalah jaring terakhir; ini menjadikannya 409, bukan 500.
            if ($order->shipment()->exists()) {
                throw $this->conflict(self::ERROR_INVALID_TRANSITION, ['status' => $from]);
            }

            $now = now();
            $dispatchedAt = $this->moment($input['dispatched_at'] ?? null) ?? $now;

            $shipment = $order->shipment()->create([
                'courier_name' => $input['courier_name'],
                'service_name' => $input['service_name'] ?? null,
                'tracking_number' => $input['tracking_number'] ?? null,
                'shipping_cost' => $order->shipping_cost,
                'dispatched_at' => $dispatchedAt,
            ]);

            $order->status = $to;
            $order->save();

            $shipmentValues = [
                'courier_name' => $shipment->courier_name,
                'service_name' => $shipment->service_name,
                'tracking_number' => $shipment->tracking_number,
                'dispatched_at' => $dispatchedAt->toIso8601String(),
            ];

            $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $staff, $input['note'] ?? null, [
                'action' => self::ACTION_SHIP,
                'from' => $from,
            ] + $shipmentValues, $now);

            $this->audit($staff, self::ACTION_SHIP, $order, "Pesanan pelanggan {$order->order_number} dikirim lewat {$shipment->courier_name}.", $from, $shipmentValues);

            return $order;
        });
    }

    /**
     * Memperbarui nomor resi / nama layanan selama pesanan `shipped` (status
     * TIDAK berubah). Baris pengiriman menyimpan nilai TERKINI; riwayatnya
     * adalah peristiwa `tracking_updated` baru berisi nilai lama dan baru.
     * Peristiwa lama tidak pernah ditulis ulang (model append-only).
     *
     * Hanya kunci yang DIKIRIM yang diubah; `null` eksplisit mengosongkan.
     * Bila tidak ada nilai yang benar-benar berubah, tidak ada peristiwa
     * maupun log yang ditulis (permintaan ulang aman).
     *
     * @param  array{tracking_number?: string|null, service_name?: string|null}  $input  sudah divalidasi
     */
    public function updateShipment(CustomerOrder $order, User $staff, array $input): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $input) {
            $order = $this->lock($order);

            if (! $order->isCourier()) {
                throw $this->conflict(self::ERROR_WRONG_FULFILMENT_METHOD);
            }

            $shipment = self::shipmentEditable($order) ? $this->lockShipment($order) : null;

            if ($shipment === null) {
                throw $this->conflict(self::ERROR_INVALID_TRANSITION, ['status' => $order->status]);
            }

            $old = [];
            $new = [];

            foreach (['tracking_number', 'service_name'] as $field) {
                if (array_key_exists($field, $input) && $input[$field] !== $shipment->{$field}) {
                    $old[$field] = $shipment->{$field};
                    $new[$field] = $input[$field];
                }
            }

            if ($new === []) {
                return $order;
            }

            $shipment->update($new);

            $this->appendEvent($order, $order->status, CustomerOrderStatusEvent::EVENT_TRACKING_UPDATED, $staff, null, [
                'old' => $old,
                'new' => $new,
            ], now());

            $this->activityLogger->log(
                userId: $staff->id,
                action: 'customer_order_shipment_update',
                entityType: 'CustomerOrder',
                entityId: $order->id,
                description: "Data pengiriman pesanan pelanggan {$order->order_number} diperbarui.",
                oldValues: $old,
                newValues: $new,
            );

            return $order;
        });
    }

    /**
     * shipped → delivered; pesanan selesai. `delivered_at` adalah waktu barang
     * diterima menurut catatan toko (bawaan: sekarang) dan menjadi
     * `completed_at` pesanan.
     *
     * @param  array{delivered_at?: string|null, note?: string|null}  $input  sudah divalidasi (≤ sekarang)
     *
     * @throws ValidationException 422 `delivered_at` lebih awal daripada waktu pengiriman
     */
    public function deliver(CustomerOrder $order, User $staff, array $input = []): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $input) {
            $order = $this->lock($order);
            $from = $order->status;
            $to = $this->guard($order, self::ACTION_DELIVER);

            $shipment = $this->lockShipment($order)
                ?? throw $this->conflict(self::ERROR_INVALID_TRANSITION, ['status' => $from]);

            $now = now();
            $deliveredAt = $this->moment($input['delivered_at'] ?? null) ?? $now;

            if ($deliveredAt->lt($shipment->dispatched_at)) {
                throw ValidationException::withMessages([
                    'delivered_at' => __('customer_commerce.fulfilment.delivered_before_dispatch'),
                ]);
            }

            $shipment->update(['delivered_at' => $deliveredAt]);

            $order->status = $to;
            $order->completed_at = $deliveredAt;
            $order->save();

            $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $staff, $input['note'] ?? null, [
                'action' => self::ACTION_DELIVER,
                'from' => $from,
                'delivered_at' => $deliveredAt->toIso8601String(),
            ], $now);

            $this->audit($staff, self::ACTION_DELIVER, $order, "Pesanan pelanggan {$order->order_number} ditandai terkirim.", $from, [
                'delivered_at' => $deliveredAt->toIso8601String(),
            ]);

            return $order;
        });
    }

    /**
     * → cancelled, hanya sebelum barang keluar (lihat TRANSITIONS).
     *
     * Bila stok sudah dikurangi (`stock_deducted_at` terisi), stok
     * dikembalikan per item lewat StockService (jenis `return`, referensi
     * yang sama dengan pengurangannya). Pembatalan sebelum konfirmasi tidak
     * menulis pergerakan stok sama sekali. "Tepat satu kali" dijamin oleh
     * kunci baris + tabel transisi: pesanan `cancelled` tidak bisa dibatalkan lagi.
     *
     * `$reason` dilihat pelanggan (alasan pembatalan + catatan linimasa), jadi
     * TIDAK disalin ke activity log.
     */
    public function cancel(CustomerOrder $order, User $staff, string $reason): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $reason) {
            $order = $this->lock($order);
            $from = $order->status;
            $to = $this->guard($order, self::ACTION_CANCEL);

            $restoresStock = $order->stock_deducted_at !== null;

            if ($restoresStock) {
                $this->restoreStock($order, $staff);
            }

            $now = now();

            $order->status = $to;
            $order->cancel_reason = $reason;
            $order->cancelled_at = $now;
            $order->save();

            $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $staff, $reason, [
                'action' => self::ACTION_CANCEL,
                'from' => $from,
                'stock_restored' => $restoresStock,
            ], $now);

            $this->audit(
                $staff,
                self::ACTION_CANCEL,
                $order,
                "Pesanan pelanggan {$order->order_number} dibatalkan".($restoresStock ? '; stok dikembalikan.' : '.'),
                $from,
                ['stock_restored' => $restoresStock],
            );

            return $order;
        }, self::DEADLOCK_ATTEMPTS); // mengunci baris varian: ulangi bila terkena deadlock (lihat DEADLOCK_ATTEMPTS)
    }

    // ------------------------------------------------------------------
    // Bagian dalam
    // ------------------------------------------------------------------

    /** Transisi tanpa efek samping selain status (kemas, siap diambil, diambil). */
    private function move(CustomerOrder $order, User $staff, string $action, ?string $note, string $verb, bool $completes = false): CustomerOrder
    {
        return DB::transaction(function () use ($order, $staff, $action, $note, $verb, $completes) {
            $order = $this->lock($order);
            $from = $order->status;
            $to = $this->guard($order, $action);
            $now = now();

            $order->status = $to;
            if ($completes) {
                $order->completed_at = $now;
            }
            $order->save();

            $this->appendEvent($order, $to, CustomerOrderStatusEvent::EVENT_STATUS_CHANGED, $staff, $note, [
                'action' => $action,
                'from' => $from,
            ], $now);

            $this->audit($staff, $action, $order, "Pesanan pelanggan {$order->order_number} {$verb}.", $from);

            return $order;
        });
    }

    /**
     * Baris pesanan TERKUNCI dan terbaru. Lewat scope mode data: pesanan mode
     * lain tidak ditemukan (404), sama seperti di route-model binding.
     */
    private function lock(CustomerOrder $order): CustomerOrder
    {
        return CustomerOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }

    private function lockShipment(CustomerOrder $order): ?CustomerOrderShipment
    {
        return $order->shipment()->lockForUpdate()->first();
    }

    /** Status tujuan untuk $action dari keadaan TERKUNCI pesanan, atau 409 berkode. */
    private function guard(CustomerOrder $order, string $action): string
    {
        $result = self::evaluate($action, $order->status, $order->fulfilment_method);

        if ($result['error'] !== null) {
            throw $this->conflict($result['error'], ['status' => $order->status]);
        }

        return $result['to'];
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $errors
     */
    private function conflict(string $reason, array $params = [], ?array $errors = null): CustomerCommerceException
    {
        return new CustomerCommerceException(
            (string) __("customer_commerce.fulfilment.{$reason}", $params),
            $reason,
            409,
            $errors,
        );
    }

    /**
     * Item pesanan urut id varian (lalu id item) beserta variannya yang
     * TERKUNCI. Urutan kunci selalu id varian menaik — sama dengan checkout —
     * supaya dua transaksi yang menyentuh varian yang sama tidak saling
     * mengunci (deadlock).
     *
     * Varian dicari lewat scope biasanya (mode data, belum dihapus, seller):
     * varian yang sudah dihapus atau di luar jangkauan = tidak ada di hasil.
     *
     * @return array{0: Collection<int, CustomerOrderItem>, 1: Collection<int, ProductVariant>}
     */
    private function itemsWithLockedVariants(CustomerOrder $order): array
    {
        $items = $order->items()->reorder()->orderBy('variant_id')->orderBy('id')->get();

        $variants = ProductVariant::query()
            ->whereIn('id', $items->pluck('variant_id')->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$items, $variants];
    }

    /**
     * Mengurangi stok seluruh item, atau tidak sama sekali. Semua baris
     * diperiksa dulu (di bawah kunci) sehingga staf melihat SEMUA baris yang
     * bermasalah sekaligus, bukan satu per percobaan.
     */
    private function deductStock(CustomerOrder $order, User $staff): void
    {
        [$items, $variants] = $this->itemsWithLockedVariants($order);

        // Varian yang sama di lebih dari satu baris dijumlahkan sebelum dibandingkan dengan stoknya.
        $needed = [];
        foreach ($items as $item) {
            $needed[$item->variant_id] = ($needed[$item->variant_id] ?? 0) + (int) $item->qty;
        }

        $problems = [];
        foreach ($items as $item) {
            $variant = $variants->get($item->variant_id);

            if ($variant === null) {
                $problems[] = $this->stockProblem($item, self::ERROR_VARIANT_UNAVAILABLE, (int) $item->qty, 0);
            } elseif ($needed[$item->variant_id] > (int) $variant->current_stock) {
                $problems[] = $this->stockProblem($item, self::ERROR_INSUFFICIENT_STOCK, $needed[$item->variant_id], (int) $variant->current_stock);
            }
        }

        if ($problems !== []) {
            $missing = in_array(self::ERROR_VARIANT_UNAVAILABLE, array_column($problems, 'reason'), true);

            throw $this->conflict(
                $missing ? self::ERROR_VARIANT_UNAVAILABLE : self::ERROR_INSUFFICIENT_STOCK,
                errors: ['items' => $problems],
            );
        }

        foreach ($items as $item) {
            $this->moveStock($variants->get($item->variant_id), 'sale', -(int) $item->qty, $item, $staff);
        }
    }

    /** Mengembalikan stok seluruh item pesanan yang stoknya sudah dikurangi, atau tidak sama sekali. */
    private function restoreStock(CustomerOrder $order, User $staff): void
    {
        [$items, $variants] = $this->itemsWithLockedVariants($order);

        $problems = [];
        foreach ($items as $item) {
            if (! $variants->has($item->variant_id)) {
                $problems[] = $this->stockProblem($item, self::ERROR_VARIANT_UNAVAILABLE, (int) $item->qty, 0);
            }
        }

        if ($problems !== []) {
            throw $this->conflict(self::ERROR_VARIANT_UNAVAILABLE, errors: ['items' => $problems]);
        }

        foreach ($items as $item) {
            $this->moveStock($variants->get($item->variant_id), 'return', (int) $item->qty, $item, $staff);
        }
    }

    /**
     * Satu-satunya titik kelas ini menyentuh stok. Galat StockService (422
     * stok kurang, 404 varian tak terjangkau) seharusnya sudah tertangkap
     * pemeriksaan di atas; bila tetap terjadi, ia dipetakan ke 409 berkode
     * (konflik bisnis) alih-alih bocor sebagai 422/404 yang menyesatkan.
     */
    private function moveStock(ProductVariant $variant, string $type, int $qtyChange, CustomerOrderItem $item, User $staff): void
    {
        try {
            $this->stock->applyMovement(
                variant: $variant,
                type: $type,
                qtyChange: $qtyChange,
                referenceType: self::STOCK_REFERENCE,
                referenceId: $item->id,
                userId: $staff->id,
            );
        } catch (ValidationException) {
            throw $this->conflict(self::ERROR_INSUFFICIENT_STOCK);
        } catch (ModelNotFoundException) {
            throw $this->conflict(self::ERROR_VARIANT_UNAVAILABLE);
        }
    }

    /** @return array{item_id: int, variant_id: int, sku: string, name: string, reason: string, requested: int, available: int} */
    private function stockProblem(CustomerOrderItem $item, string $reason, int $requested, int $available): array
    {
        return [
            'item_id' => $item->id,
            'variant_id' => (int) $item->variant_id,
            'sku' => $item->sku_snapshot,
            'name' => $item->name_snapshot,
            'reason' => $reason,
            'requested' => $requested,
            'available' => $available,
        ];
    }

    /**
     * Menambah SATU baris riwayat. `note` teks polos yang dilihat pelanggan;
     * `metadata` detail audit khusus staf (tidak pernah dikirim ke pelanggan).
     * `occurred_at` selalu waktu pencatatan, sehingga urutan linimasa tidak
     * bisa dikacaukan tanggal kirim/terima yang diisi mundur.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private function appendEvent(CustomerOrder $order, string $status, string $eventType, User $staff, ?string $note, ?array $metadata, Carbon $occurredAt): CustomerOrderStatusEvent
    {
        return $order->statusEvents()->create([
            'status' => $status,
            'event_type' => $eventType,
            'note' => $note,
            'actor_type' => CustomerOrderStatusEvent::ACTOR_STAFF,
            'actor_user_id' => $staff->id,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Log aktivitas satu transisi, DI DALAM transaksi yang sama. Deskripsi dan
     * nilainya hanya memuat nomor pesanan, status, dan data operasional —
     * tidak pernah nama, kontak, alamat, atau catatan pelanggan.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(User $staff, string $action, CustomerOrder $order, string $description, string $from, array $extra = []): void
    {
        $this->activityLogger->log(
            userId: $staff->id,
            action: "customer_order_{$action}",
            entityType: 'CustomerOrder',
            entityId: $order->id,
            description: $description,
            oldValues: ['status' => $from],
            newValues: ['status' => $order->status] + $extra,
        );
    }

    /**
     * Waktu dari masukan (ISO-8601 dengan offset) dinormalkan ke zona waktu
     * aplikasi: Eloquent menulis Carbon dalam zona waktunya sendiri TANPA
     * mengonversi, jadi "10:00+09:00" akan tersimpan sebagai 10:00 lokal.
     */
    private function moment(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
