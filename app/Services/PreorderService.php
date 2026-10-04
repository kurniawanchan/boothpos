<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Preorder;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Couriers;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreorderService
{
    /** Relasi yang dibaca PreorderController::present() — dimuat ulang agar field tak hilang diam-diam. */
    public const PAYLOAD_RELATIONS = [
        'items.artist', 'items.variant.product.category', 'payments.proofs', 'payments.recorder', 'shipment', 'customer', 'splitChildren',
    ];

    public function __construct(
        private StockService $stockService,
        private PaymentRecorder $paymentRecorder,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Stok TIDAK berkurang di sini — barang belum ada secara fisik.
     * Lihat uml-pos-mvp.md sequence diagram pre-order.
     */
    public function create(array $data, User $user): Preorder
    {
        // 003-seed-demo-live — sama seperti OrderService::create(): tanpa
        // lookup Eloquent ini, 'customer_id' lintas mode lolos begitu saja
        // (lihat komentar lengkap di OrderService).
        try {
            Customer::findOrFail($data['customer_id']);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([
                'customer_id' => __('preorders.customer_not_found'),
            ]);
        }

        return DB::transaction(function () use ($data, $user) {
            $subtotal = 0;
            $lineData = [];

            foreach ($data['items'] as $itemInput) {
                $variant = ProductVariant::with('product')->findOrFail($itemInput['variant_id']);
                $qty = (int) $itemInput['qty'];
                $lineTotal = (float) $variant->sell_price * $qty;

                $lineData[] = ['variant' => $variant, 'qty' => $qty, 'line_total' => $lineTotal];
                $subtotal += $lineTotal;
            }

            $shippingCost = (float) ($data['shipping_cost'] ?? 0);

            // 021-preorder-form-updates (US2) — nominal Rupiah tetap,
            // dihitung SEKALI di sini dan tidak pernah dihitung ulang
            // setelahnya (snapshot, sama seperti sell_price/cost_price per
            // item — research.md Decision 3). Tidak boleh membuat total
            // negatif.
            $discount = (float) ($data['discount'] ?? 0);
            if ($discount > $subtotal + $shippingCost) {
                throw ValidationException::withMessages([
                    'discount' => __('preorders.discount_exceeds_total'),
                ]);
            }

            [$pickupDay, $courierName] = $this->resolvePickupDayAndCourier($data);

            $preorder = Preorder::create([
                'preorder_number' => $this->generateNumber(),
                'event_id' => $data['event_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'user_id' => $user->id,
                'fulfillment' => $data['fulfillment'],
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total_amount' => $subtotal + $shippingCost - $discount,
                'expected_date' => $data['expected_date'] ?? null,
                'pickup_day' => $pickupDay,
                'courier_name' => $courierName,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lineData as $line) {
                $variant = $line['variant'];
                $preorder->items()->create([
                    'variant_id' => $variant->id,
                    'artist_id' => $variant->product->artist_id,
                    'sku_snapshot' => $variant->sku,
                    'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
                    'qty' => $line['qty'],
                    'cost_price' => $variant->cost_price,
                    'sell_price' => $variant->sell_price,
                    'line_total' => $line['line_total'],
                ]);
            }

            return $preorder->load(['items', 'customer']);
        });
    }

    /**
     * 027-preorder-duplicate-split (US1, research.md Decisions 1–2) —
     * membuat salinan segar dari satu pre-order.
     *
     * Sengaja MEMBUNGKUS create(), bukan replicate(): harga diambil dari
     * varian SAAT INI (jawaban product owner), dan aturan batas diskon,
     * hari jemput/kurir, penomoran, serta stempel DEMO/LIVE tetap punya SATU
     * implementasi (Constitution I). replicate() akan ikut menyalin status,
     * pembayaran, dan snapshot harga lama yang justru harus dibuang.
     *
     * Input yang bisa basi dibersihkan dulu alih-alih membuat salinan gagal
     * (event dihapus → tanpa event; hari jemput di luar rentang → dibuang),
     * sedangkan barang yang tak lagi bisa dijual MENGGAGALKAN salinan ini —
     * diam-diam membuang baris akan mengubah isi pesanan tanpa sepengetahuan
     * pengguna.
     */
    public function duplicate(Preorder $source, User $user): Preorder
    {
        $source->loadMissing('items');

        $variants = ProductVariant::with('product')
            ->whereIn('id', $source->items->pluck('variant_id')->unique())
            ->get()->keyBy('id');

        foreach ($source->items as $item) {
            $variant = $variants->get($item->variant_id);

            // Varian/produk yang di-soft-delete (atau milik mode lain) tak
            // ikut terambil oleh query di atas; yang nonaktif diperiksa di sini.
            if (! $variant || ! $variant->is_active || ! $variant->product || ! $variant->product->is_active) {
                throw ValidationException::withMessages([
                    'items' => __('preorders.duplicate_item_unavailable', ['item' => $item->name_snapshot]),
                ]);
            }
        }

        $event = $source->event_id ? Event::find($source->event_id) : null;

        $pickupDay = null;
        if ($source->fulfillment === 'pickup' && $source->pickup_day && $event) {
            $day = $source->pickup_day->toDateString();
            if ($day >= $event->start_date->toDateString() && $day <= $event->end_date->toDateString()) {
                $pickupDay = $day;
            }
        }

        $data = [
            'customer_id' => $source->customer_id,
            'event_id' => $event?->id,
            'fulfillment' => $source->fulfillment,
            'shipping_cost' => $source->shipping_cost,
            'discount' => $source->discount,
            'expected_date' => $source->expected_date?->toDateString(),
            'pickup_day' => $pickupDay,
            'courier_name' => $source->fulfillment === 'courier' ? $source->courier_name : null,
            'notes' => $source->notes,
            'items' => $source->items
                ->map(fn ($item) => ['variant_id' => $item->variant_id, 'qty' => $item->qty])->all(),
        ];

        return DB::transaction(function () use ($data, $source, $user) {
            $copy = $this->create($data, $user);

            $copy->update([
                'source_preorder_id' => $source->id,
                'source_type' => 'duplicate',
                'source_preorder_number' => $source->preorder_number,
            ]);

            // Di dalam transaksi yang sama — salinan yang di-rollback tak
            // boleh meninggalkan log yang mengklaimnya ada.
            $this->activityLogger->log(
                userId: $user->id,
                action: 'duplicated',
                entityType: 'Preorder',
                entityId: $copy->id,
                description: "Menduplikasi {$source->preorder_number} menjadi {$copy->preorder_number}",
                newValues: ['source_preorder_id' => $source->id, 'source_preorder_number' => $source->preorder_number],
            );

            return $copy->fresh(self::PAYLOAD_RELATIONS);
        });
    }

    /**
     * 027-preorder-duplicate-split (US3, research.md Decision 4) — memindahkan
     * sebagian UNIT dari satu pre-order ke pre-order baru.
     *
     * $moves: [['item_id' => int, 'qty' => int], ...]. Baris yang sama yang
     * disebut dua kali dijumlahkan dulu sebelum divalidasi.
     *
     * @return array{original: Preorder, created: list<Preorder>}
     */
    public function split(Preorder $source, array $moves, User $user): array
    {
        return $this->performSplit($source, $user, function (Collection $items) use ($moves) {
            $wanted = [];
            foreach ($moves as $move) {
                $itemId = (int) $move['item_id'];
                $wanted[$itemId] = ($wanted[$itemId] ?? 0) + (int) $move['qty'];
            }

            foreach ($wanted as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    throw ValidationException::withMessages(['items' => __('preorders.split_item_not_in_order')]);
                }
                if ($qty > $item->qty) {
                    throw ValidationException::withMessages([
                        'items' => __('preorders.split_qty_exceeds_line', ['item' => $item->name_snapshot, 'qty' => $item->qty]),
                    ]);
                }
            }

            return [$wanted]; // satu pre-order baru berisi semua yang dipindah
        });
    }

    /**
     * 027-preorder-duplicate-split (US4, research.md Decision 5) — satu klik:
     * penjual dari baris berid terkecil TETAP di pesanan asal, tiap penjual
     * lain menjadi satu pre-order baru berisi baris-barisnya yang utuh.
     * Lewat performSplit() yang sama dengan split() manual, jadi semua
     * penjagaan (status, pembayaran, batas diskon, atomik) tidak digandakan.
     *
     * @return array{original: Preorder, created: list<Preorder>}
     */
    public function splitBySeller(Preorder $source, User $user): array
    {
        return $this->performSplit($source, $user, function (Collection $items) {
            // $items sudah terurut menurut id; groupBy mempertahankan urutan kemunculan pertama.
            $bySeller = $items->groupBy('artist_id');

            if ($bySeller->count() < 2) {
                throw ValidationException::withMessages(['mode' => __('preorders.split_single_seller')]);
            }

            return $bySeller->skip(1)
                ->map(fn (Collection $lines) => $lines->mapWithKeys(fn ($item) => [$item->id => (int) $item->qty])->all())
                ->values()->all();
        });
    }

    /**
     * Badan bersama untuk semua mode split. $plan menerima baris-baris pesanan
     * asal (keyed by id, TERKUNCI) dan mengembalikan daftar kelompok; tiap
     * kelompok ([item_id => qty]) menjadi SATU pre-order baru.
     *
     * Semua pemeriksaan status/pembayaran dilakukan SETELAH baris dikunci —
     * dua permintaan bersamaan (atau pembayaran yang baru masuk) tidak boleh
     * lolos hanya karena pemeriksaan pertama dilakukan sebelum kunci.
     *
     * TIDAK memanggil StockService sama sekali: total qty per varian tak
     * berubah oleh pemisahan, jadi stok juga tak berubah. Pergerakan stok
     * baru ditulis nanti oleh transisi status masing-masing pre-order.
     */
    private function performSplit(Preorder $source, User $user, \Closure $plan): array
    {
        return DB::transaction(function () use ($source, $user, $plan) {
            $locked = Preorder::lockForUpdate()->findOrFail($source->id);

            // 409 (bukan 422): ini konflik aturan bisnis, bukan salah bentuk input.
            if (in_array($locked->status, ['handed_over', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => __('preorders.split_not_allowed_status')])->status(409);
            }
            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages(['status' => __('preorders.split_not_allowed_has_payment')])->status(409);
            }

            $items = $locked->items()->orderBy('id')->get()->keyBy('id');
            $groups = $plan($items);

            $movedByItem = [];
            foreach ($groups as $group) {
                foreach ($group as $itemId => $qty) {
                    $movedByItem[$itemId] = ($movedByItem[$itemId] ?? 0) + $qty;
                }
            }
            $totalUnits = (int) $items->sum('qty');
            $movedUnits = (int) array_sum($movedByItem);
            if ($movedUnits < 1 || $movedUnits >= $totalUnits) {
                throw ValidationException::withMessages(['items' => __('preorders.split_must_move_and_keep')]);
            }
            foreach ($movedByItem as $itemId => $qty) {
                if ($qty > $items[$itemId]->qty) {
                    throw ValidationException::withMessages(['items' => __('preorders.split_qty_exceeds_line', [
                        'item' => $items[$itemId]->name_snapshot, 'qty' => $items[$itemId]->qty,
                    ])]);
                }
            }

            $created = [];
            $movedLog = [];
            foreach ($groups as $group) {
                $new = Preorder::create([
                    'preorder_number' => $this->generateNumber(),
                    'event_id' => $locked->event_id,
                    'customer_id' => $locked->customer_id,
                    'user_id' => $user->id,
                    // Pesanan baru mewarisi status asal: barang yang sudah "arrived"
                    // tidak diterima ulang, dan tanpa pembayaran status lain pun valid.
                    'status' => $locked->status,
                    'fulfillment' => $locked->fulfillment,
                    'expected_date' => $locked->expected_date,
                    'pickup_day' => $locked->pickup_day,
                    'courier_name' => $locked->courier_name,
                    // Ongkir, diskon, catatan, pengiriman, dan penanda invoice
                    // SENGAJA tetap di pesanan asal (spec FR-015).
                    'shipping_cost' => 0, 'discount' => 0, 'subtotal' => 0, 'total_amount' => 0, 'paid_amount' => 0,
                    'source_preorder_id' => $locked->id,
                    'source_type' => 'split',
                    'source_preorder_number' => $locked->preorder_number,
                ]);

                foreach ($group as $itemId => $qty) {
                    $item = $items[$itemId];

                    if ($qty === (int) $item->qty) {
                        // Seluruh baris pindah: pakai baris yang sama supaya id yang
                        // dirujuk stock_movements (saat "arrived") tetap benar.
                        $item->update(['preorder_id' => $new->id]);
                    } else {
                        $remaining = (int) $item->qty - $qty;
                        $item->update([
                            'qty' => $remaining,
                            'line_total' => round((float) $item->sell_price * $remaining, 2),
                        ]);
                        $new->items()->create([
                            'variant_id' => $item->variant_id, 'artist_id' => $item->artist_id,
                            'sku_snapshot' => $item->sku_snapshot, 'name_snapshot' => $item->name_snapshot,
                            'qty' => $qty, 'cost_price' => $item->cost_price, 'sell_price' => $item->sell_price,
                            'line_total' => round((float) $item->sell_price * $qty, 2),
                        ]);
                    }

                    $movedLog[] = ['item_id' => $itemId, 'qty' => $qty, 'to' => $new->preorder_number];
                }

                $subtotal = (float) $new->items()->sum('line_total');
                $new->update(['subtotal' => $subtotal, 'total_amount' => $subtotal]);
                $created[] = $new;
            }

            $subtotal = (float) $locked->items()->sum('line_total');
            // Diskon tetap di pesanan asal; bila sisanya kini lebih kecil dari
            // diskon, tolak daripada diam-diam membuat total negatif atau
            // mengubah diskon yang sudah disepakati pelanggan.
            if ((float) $locked->discount > $subtotal + (float) $locked->shipping_cost) {
                throw ValidationException::withMessages(['discount' => __('preorders.split_discount_exceeds_remaining')])->status(409);
            }
            $locked->update([
                'subtotal' => $subtotal,
                'total_amount' => $subtotal + (float) $locked->shipping_cost - (float) $locked->discount,
            ]);

            $numbers = collect($created)->pluck('preorder_number')->all();
            $this->activityLogger->log(
                userId: $user->id,
                action: 'split',
                entityType: 'Preorder',
                entityId: $locked->id,
                description: "Memisahkan {$locked->preorder_number} menjadi ".implode(', ', $numbers),
                newValues: ['created' => $numbers, 'moved' => $movedLog],
            );

            return [
                'original' => $locked->fresh(self::PAYLOAD_RELATIONS),
                'created' => array_map(fn (Preorder $p) => $p->fresh(self::PAYLOAD_RELATIONS), $created),
            ];
        });
    }

    /**
     * 021-preorder-form-updates (US3) — resolusi & validasi cross-field
     * untuk pickup_day/courier_name. Dipakai baik oleh create() di sini
     * maupun PreorderExportImportService::import() (Constitution I — satu
     * definisi aturan, bukan diduplikasi).
     *
     * @return array{0: ?string, 1: ?string} [pickup_day (Y-m-d atau null), courier_name atau null]
     */
    public function resolvePickupDayAndCourier(array $data): array
    {
        $fulfillment = $data['fulfillment'];
        $pickupDayInput = $data['pickup_day'] ?? null;
        $courierInput = $data['courier_name'] ?? null;

        if ($fulfillment === 'courier') {
            // FR-014 — pickup_day tidak berlaku untuk fulfillment courier.
            if ($pickupDayInput !== null) {
                throw ValidationException::withMessages([
                    'pickup_day' => __('preorders.pickup_day_not_applicable'),
                ]);
            }

            // research.md Decision 1 — default JNE kalau tidak diisi.
            return [null, $courierInput ?: Couriers::DEFAULT];
        }

        // fulfillment === 'pickup'
        if ($courierInput !== null) {
            throw ValidationException::withMessages([
                'courier_name' => __('preorders.courier_not_applicable'),
            ]);
        }

        if ($pickupDayInput === null) {
            return [null, null];
        }

        // FR-008a — hari jemput butuh event yang ditautkan, tidak ada
        // picker generik "Day 1/Day 2" tanpa tanggal asli untuk divalidasi.
        if (empty($data['event_id'])) {
            throw ValidationException::withMessages([
                'pickup_day' => __('preorders.pickup_day_requires_event'),
            ]);
        }

        $event = Event::find($data['event_id']);
        if (! $event) {
            throw ValidationException::withMessages([
                'pickup_day' => __('preorders.pickup_day_requires_event'),
            ]);
        }

        $pickupDay = Carbon::parse($pickupDayInput)->toDateString();
        if ($pickupDay < $event->start_date->toDateString() || $pickupDay > $event->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'pickup_day' => __('preorders.pickup_day_out_of_range'),
            ]);
        }

        return [$pickupDay, null];
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US1, FR-001/FR-001a/FR-002,
     * research.md Decision 3) — edit ditolak untuk status handed_over/
     * cancelled (transaksi sudah tertutup). Baris item YANG TIDAK BERUBAH
     * (variant_id sama) mempertahankan snapshot harga lamanya — hanya qty
     * & line_total-nya yang dihitung ulang; baris BARU memakai snapshot
     * harga variant SAAT INI, sama seperti create(). Efek stok dihitung
     * sebagai DELTA per variant (qty baru − qty lama), bukan
     * membalik-lalu-menerapkan-ulang seluruh item — supaya variant yang
     * tidak berubah sama sekali tidak pernah mendapat baris stock_movements
     * baru (data-model.md).
     */
    public function update(Preorder $preorder, array $data, User $user): Preorder
    {
        if (in_array($preorder->status, ['handed_over', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => __('preorders.edit_not_allowed_status'),
            ]);
        }

        if (array_key_exists('customer_id', $data)) {
            try {
                Customer::findOrFail($data['customer_id']);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages([
                    'customer_id' => __('preorders.customer_not_found'),
                ]);
            }
        }

        return DB::transaction(function () use ($preorder, $data, $user) {
            $preorder->loadMissing('items');
            $oldItemsById = $preorder->items->keyBy('id');

            $subtotal = 0;
            $newItemsData = [];
            $newQtyByVariant = [];
            $matchedOldIds = [];

            foreach ($data['items'] as $itemInput) {
                $variantId = (int) $itemInput['variant_id'];
                $qty = (int) $itemInput['qty'];
                $itemId = isset($itemInput['id']) ? (int) $itemInput['id'] : null;
                $existing = $itemId !== null ? $oldItemsById->get($itemId) : null;

                // BUG YANG DITEMUKAN & DIPERBAIKI — pencocokan "baris ini
                // masih baris yang sama" dulu memakai variant_id, bukan id
                // baris preorder_items itu sendiri. Akibatnya, MENGHAPUS
                // baris lalu MENAMBAH KEMBALI varian yang sama (mis. untuk
                // memperbaiki harga yang salah/basi) tidak bisa dibedakan
                // dari sekadar mengubah qty baris lama — harga lama yang
                // salah selalu dipertahankan, tidak pernah diambil ulang
                // dari harga varian saat ini. Dicocokkan lewat id di sini:
                // id yang dikenal (dan variant_id-nya tetap cocok) berarti
                // benar-benar baris lama (qty baru, harga snapshot lama
                // dipertahankan); id kosong/tidak dikenal/variant_id tidak
                // cocok berarti baris BARU (harga diambil ulang dari
                // variant->sell_price saat ini), meski varian yang dipilih
                // kebetulan sama dengan salah satu baris lama.
                $identityMatches = $existing !== null
                    && (int) $existing->variant_id === $variantId
                    && ! in_array($itemId, $matchedOldIds, true);

                if ($identityMatches) {
                    $matchedOldIds[] = $itemId;
                    // Baris YANG TIDAK BERUBAH secara identitas — pertahankan
                    // snapshot harga lama, cuma qty/line_total yang baru.
                    $sellPrice = (float) $existing->sell_price;
                    $lineTotal = $sellPrice * $qty;
                    $newItemsData[] = [
                        'variant_id' => $variantId, 'artist_id' => $existing->artist_id,
                        'sku_snapshot' => $existing->sku_snapshot, 'name_snapshot' => $existing->name_snapshot,
                        'qty' => $qty, 'cost_price' => $existing->cost_price,
                        'sell_price' => $existing->sell_price, 'line_total' => $lineTotal,
                    ];
                } else {
                    $variant = ProductVariant::with('product')->findOrFail($variantId);
                    $lineTotal = (float) $variant->sell_price * $qty;
                    $newItemsData[] = [
                        'variant_id' => $variantId, 'artist_id' => $variant->product->artist_id,
                        'sku_snapshot' => $variant->sku,
                        'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
                        'qty' => $qty, 'cost_price' => $variant->cost_price,
                        'sell_price' => $variant->sell_price, 'line_total' => $lineTotal,
                    ];
                }

                $subtotal += $lineTotal;
                $newQtyByVariant[$variantId] = ($newQtyByVariant[$variantId] ?? 0) + $qty;
            }

            $shippingCost = array_key_exists('shipping_cost', $data)
                ? (float) $data['shipping_cost'] : (float) $preorder->shipping_cost;
            $discount = array_key_exists('discount', $data)
                ? (float) $data['discount'] : (float) $preorder->discount;

            if ($discount > $subtotal + $shippingCost) {
                throw ValidationException::withMessages([
                    'discount' => __('preorders.discount_exceeds_total'),
                ]);
            }

            $totalAmount = $subtotal + $shippingCost - $discount;

            // Edge Cases (spec.md) — total baru tidak boleh membuat uang yang
            // sudah dibayar pelanggan melebihi total pesanan; edit yang
            // membuat ini terjadi ditolak eksplisit, bukan diam-diam
            // menghasilkan outstanding negatif.
            if ($totalAmount < (float) $preorder->paid_amount) {
                throw ValidationException::withMessages([
                    'items' => __('preorders.edit_total_below_paid_amount'),
                ]);
            }

            $resolveInput = [
                'fulfillment' => $data['fulfillment'] ?? $preorder->fulfillment,
                'pickup_day' => array_key_exists('pickup_day', $data) ? $data['pickup_day'] : $preorder->pickup_day?->toDateString(),
                'courier_name' => array_key_exists('courier_name', $data) ? $data['courier_name'] : $preorder->courier_name,
                'event_id' => array_key_exists('event_id', $data) ? $data['event_id'] : $preorder->event_id,
            ];
            [$pickupDay, $courierName] = $this->resolvePickupDayAndCourier($resolveInput);

            // research.md Decision 3 — hanya pada status arrived/settled stok
            // SUDAH pernah ditambahkan (movement type 'purchase' saat
            // transisi ke 'arrived'); di ordered/dp_paid belum ada efek stok
            // sama sekali, jadi tidak ada yang perlu dikoreksi.
            if (in_array($preorder->status, ['arrived', 'settled'], true)) {
                // Stok peduli pada TOTAL qty per varian, bukan identitas
                // baris mana pun — dijumlah ulang di sini terlepas dari
                // pencocokan by-id di atas (satu varian bisa saja muncul
                // di lebih dari satu baris lama dalam skenario yang jarang).
                $oldQtyByVariant = $preorder->items->groupBy('variant_id')->map(fn ($items) => (int) $items->sum('qty'));
                $allVariantIds = array_unique([...$oldQtyByVariant->keys()->all(), ...array_keys($newQtyByVariant)]);

                foreach ($allVariantIds as $variantId) {
                    $oldQty = (int) ($oldQtyByVariant->get($variantId) ?? 0);
                    $newQty = (int) ($newQtyByVariant[$variantId] ?? 0);
                    $delta = $newQty - $oldQty;

                    if ($delta === 0) {
                        continue;
                    }

                    $variant = ProductVariant::lockForUpdate()->findOrFail($variantId);
                    $this->stockService->applyMovement(
                        variant: $variant, type: 'purchase', qtyChange: $delta,
                        referenceType: 'preorder_item', referenceId: $preorder->id, userId: $user->id,
                    );
                }
            }

            $preorder->items()->delete();
            foreach ($newItemsData as $itemData) {
                $preorder->items()->create($itemData);
            }

            $preorder->update([
                'event_id' => $resolveInput['event_id'],
                'customer_id' => $data['customer_id'] ?? $preorder->customer_id,
                'fulfillment' => $resolveInput['fulfillment'],
                'subtotal' => $subtotal,
                'shipping_cost' => $shippingCost,
                'discount' => $discount,
                'total_amount' => $totalAmount,
                'expected_date' => array_key_exists('expected_date', $data) ? $data['expected_date'] : $preorder->expected_date,
                'pickup_day' => $pickupDay,
                'courier_name' => $courierName,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $preorder->notes,
                // "Shipping in progress" hanya berlaku untuk Mail Order. Bila
                // edit memindahkan pesanan ke pickup, turunkan ke
                // 'invoice_sent' (invoice-nya memang sudah terkirim) alih-alih
                // membiarkan penanda pengiriman yang mustahil tertinggal.
                'dispatch_status' => ($resolveInput['fulfillment'] !== 'courier' && $preorder->dispatch_status === 'shipping')
                    ? 'invoice_sent'
                    : $preorder->dispatch_status,
                'shipping_at' => ($resolveInput['fulfillment'] !== 'courier' && $preorder->dispatch_status === 'shipping')
                    ? null
                    : $preorder->shipping_at,
            ]);

            return $preorder->fresh(['items', 'payments.proofs', 'payments.recorder', 'customer', 'shipment']);
        });
    }

    /**
     * 022-preorder-invoice-crud-overhaul (US1, FR-003/FR-004) — hanya
     * boleh dihapus selama status masih "ordered" (tahap paling awal,
     * belum ada pergerakan stok atau pembayaran sama sekali). Status lain
     * WAJIB memakai aksi "Cancel" yang sudah ada.
     */
    public function delete(Preorder $preorder): void
    {
        // 024-invoice-layout-shipping-slip lanjutan — "Cancelled" ditambah
        // ke status yang boleh dihapus (sebelumnya cuma "Ordered"). Sebuah
        // preorder yang sudah dibatalkan adalah status akhir tanpa dampak
        // bisnis aktif lagi, jadi tidak ada alasan menahannya selamanya di
        // daftar. Guard pembayaran di bawah TETAP berlaku untuk status ini
        // juga — preorder dibatalkan SETELAH sempat menerima pembayaran
        // (DP lalu batal) tetap tidak boleh dihapus, supaya jejak transaksi
        // uang yang pernah masuk tidak pernah hilang begitu saja.
        if (! in_array($preorder->status, ['ordered', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => __('preorders.delete_not_allowed_status'),
            ]);
        }

        if ($preorder->payments()->exists()) {
            throw ValidationException::withMessages([
                'status' => __('preorders.delete_not_allowed_has_payment'),
            ]);
        }

        DB::transaction(function () use ($preorder) {
            $preorder->items()->delete();
            $preorder->delete();
        });
    }

    /**
     * 028-partial-split-payment — delegasi ke PaymentService (satu-satunya jalur
     * pencatatan pembayaran untuk pre-order dan POS). `$user` opsional supaya
     * pemanggil non-HTTP (mis. test laporan) tetap bisa memakainya.
     */
    public function recordPayment(Preorder $preorder, array $paymentInput, ?User $user = null, ?bool &$replayed = null): Preorder
    {
        return app(PaymentService::class)->addPayment($preorder, $paymentInput, $user, $replayed);
    }

    /**
     * 028-partial-split-payment — delegasi ke PaymentService (logika hapus
     * pembayaran, hitung ulang status, audit, dan hapus file bukti ada di sana).
     */
    public function deletePayment(Preorder $preorder, Payment $payment, User $user): Preorder
    {
        return app(PaymentService::class)->deletePayment($preorder, $payment, $user);
    }

    /**
     * 031-optional-payment-proof — delegasi ke PaymentService (tambah/ubah/ganti bukti,
     * referensi, catatan sebuah pembayaran; aturan siapa boleh, transaksi batal, tunai, hasil
     * tak boleh kosong, supersede bukti lama, dan audit ada di sana).
     */
    public function updatePaymentConfirmation(Preorder $preorder, Payment $payment, array $input, User $user): Preorder
    {
        return app(PaymentService::class)->updateConfirmation($preorder, $payment, $input, $user);
    }

    public function transitionStatus(Preorder $preorder, string $newStatus, ?string $cancelReason, User $user): Preorder
    {
        if (! $preorder->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => __('preorders.invalid_status_transition', ['from' => $preorder->status, 'to' => $newStatus]),
            ]);
        }

        if ($newStatus === 'handed_over' && $preorder->outstanding() > 0.01) {
            throw ValidationException::withMessages([
                'status' => __('preorders.not_fully_paid', ['outstanding' => $preorder->outstanding()]),
            ]);
        }

        return DB::transaction(function () use ($preorder, $newStatus, $cancelReason, $user) {
            if ($newStatus === 'arrived') {
                foreach ($preorder->items as $item) {
                    $variant = ProductVariant::lockForUpdate()->findOrFail($item->variant_id);
                    $this->stockService->applyMovement(
                        variant: $variant, type: 'purchase', qtyChange: $item->qty,
                        referenceType: 'preorder_item', referenceId: $item->id, userId: $user->id,
                    );
                }
            }

            if ($newStatus === 'handed_over') {
                foreach ($preorder->items as $item) {
                    $variant = ProductVariant::lockForUpdate()->findOrFail($item->variant_id);
                    $this->stockService->applyMovement(
                        variant: $variant, type: 'preorder_handover', qtyChange: -$item->qty,
                        referenceType: 'preorder_item', referenceId: $item->id, userId: $user->id,
                    );
                }
            }

            $preorder->update([
                'status' => $newStatus,
                'cancel_reason' => $newStatus === 'cancelled' ? $cancelReason : $preorder->cancel_reason,
            ]);

            return $preorder->fresh(['items', 'payments.proofs', 'payments.recorder', 'shipment', 'customer']);
        });
    }

    /**
     * 007-preorder-import-export-notify (US4) — dipanggil SETELAH
     * transaksi status commit (di atas), bukan di dalamnya: pengiriman
     * email adalah operasi jaringan lambat/rawan gagal yang TIDAK BOLEH
     * membuat perubahan status bisnis ini sendiri gagal atau rollback
     * (research.md R7). Dibungkus try/catch tambahan di sini supaya
     * bahkan galat TAK TERDUGA dari PreorderNotifier (bukan cuma galat
     * kirim email yang sudah ditangani di dalamnya) tidak pernah bisa
     * membuat respons transitionStatus() gagal.
     */
    public function notifyStatusChangeSafely(Preorder $preorder): void
    {
        try {
            app(PreorderNotifier::class)->notifyStatusChange($preorder, 'status_change');
        } catch (\Throwable) {
            // Sengaja ditelan — lihat docblock di atas. Percobaan yang
            // gagal DI DALAM PreorderNotifier sudah tercatat sebagai baris
            // 'failed'; ini hanya jaring pengaman untuk galat yang bahkan
            // lolos dari situ (mis. galat menulis baris audit itu sendiri).
        }
    }

    /** 003-seed-demo-live — sama seperti OrderService::generateOrderNumber(): `preorder_number` unik lintas seluruh tabel, jadi hitungannya HARUS lintas mode juga. */
    /**
     * 007-preorder-import-export-notify (US3) — publik (bukan privat lagi)
     * supaya PreorderExportImportService::import() bisa memakai jalur
     * penomoran yang sama persis untuk pre-order hasil impor, alih-alih
     * menduplikasi logika ini di kelas lain (Constitution I).
     */
    public function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = Preorder::withoutGlobalScope(DataModeScope::class)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return sprintf('PO-%s-%04d', $today, $countToday + 1);
    }
}
