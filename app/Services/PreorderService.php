<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Customer;
use App\Models\Preorder;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PreorderService
{
    public function __construct(
        private StockService $stockService,
        private PaymentRecorder $paymentRecorder,
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
                'total_amount' => $subtotal + $shippingCost,
                'expected_date' => $data['expected_date'] ?? null,
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
            $oldItemsByVariant = $preorder->items->keyBy('variant_id');

            $subtotal = 0;
            $newItemsData = [];
            $newQtyByVariant = [];

            foreach ($data['items'] as $itemInput) {
                $variantId = (int) $itemInput['variant_id'];
                $qty = (int) $itemInput['qty'];
                $existing = $oldItemsByVariant->get($variantId);

                if ($existing) {
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
                $newQtyByVariant[$variantId] = $qty;
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
                $allVariantIds = array_unique([...$oldItemsByVariant->keys()->all(), ...array_keys($newQtyByVariant)]);

                foreach ($allVariantIds as $variantId) {
                    $oldQty = (int) ($oldItemsByVariant->get($variantId)?->qty ?? 0);
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
            ]);

            return $preorder->fresh(['items', 'payments', 'customer', 'shipment']);
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
        if ($preorder->status !== 'ordered') {
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

    public function recordPayment(Preorder $preorder, array $paymentInput): Preorder
    {
        return DB::transaction(function () use ($preorder, $paymentInput) {
            $this->paymentRecorder->record($paymentInput, null, $preorder->id);

            $newPaidAmount = (float) $preorder->paid_amount + (float) $paymentInput['amount'];
            $preorder->update(['paid_amount' => $newPaidAmount]);

            // Auto-transisi status berdasar pembayaran — sejalan dengan
            // state machine, tanpa perlu panggilan status terpisah dari
            // klien untuk kasus umum ini.
            if ($preorder->status === 'ordered') {
                $preorder->update(['status' => 'dp_paid']);
            } elseif ($preorder->status === 'arrived' && $newPaidAmount >= (float) $preorder->total_amount) {
                $preorder->update(['status' => 'settled']);
            }

            return $preorder->fresh(['items', 'payments', 'customer', 'shipment']);
        });
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

            return $preorder->fresh(['items', 'payments', 'shipment', 'customer']);
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
