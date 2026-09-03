<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Concerns\DataModeScope;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private StockService $stockService,
        private PaymentRecorder $paymentRecorder,
    ) {}

    /**
     * Satu transaksi database untuk: order, order_items (snapshot harga
     * dan artist), payments, payment_proofs (link), dan stock_movements.
     * Bila satu langkah gagal, seluruhnya dibatalkan — mencegah kondisi
     * "stok berkurang tapi transaksi tidak tercatat" atau sebaliknya
     * (lihat sequence diagram transaksi kasir, uml-pos-mvp.md bagian 3).
     *
     * @throws ValidationException
     */
    public function create(array $data, User $cashier): Order
    {
        // Idempotensi — dicek DI LUAR transaksi lock supaya permintaan
        // yang benar-benar duplikat langsung dikembalikan tanpa membuka
        // transaksi baru sama sekali.
        if (! empty($data['local_ref'])) {
            $existing = Order::where('local_ref', $data['local_ref'])->first();
            if ($existing) {
                return $existing->load(['items', 'payments']);
            }
        }

        $session = CashierSession::findOrFail($data['session_id']);
        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'session_id' => __('orders_payments.session_not_open'),
            ]);
        }

        // 003-seed-demo-live (US3, research.md Decision 3) — 'customer_id'
        // ditulis langsung ke Order::create() tanpa lookup Eloquent apa pun
        // (beda dengan session_id/variant_id di atas/bawah yang sudah aman
        // lewat findOrFail() terhadap model ber-HasDataMode). Validasi
        // `exists:customers,id` di StoreOrderRequest juga tidak menolong —
        // rule bawaan Laravel itu memakai query mentah yang tidak ikut
        // Eloquent global scope, jadi id customer DEMO tetap lolos selagi
        // LIVE aktif (atau sebaliknya) tanpa pengecekan eksplisit ini.
        if (! empty($data['customer_id'])) {
            try {
                Customer::findOrFail($data['customer_id']);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages([
                    'customer_id' => __('orders_payments.customer_not_found'),
                ]);
            }
        }

        return DB::transaction(function () use ($data, $cashier, $session) {
            $subtotal = 0;
            $totalCost = 0;
            $lineData = [];

            // Harga diambil dari MASTER DATA di server, tidak pernah dari
            // payload klien — mencegah klien menentukan harganya sendiri.
            foreach ($data['items'] as $itemInput) {
                $variant = ProductVariant::with('product')->lockForUpdate()->findOrFail($itemInput['variant_id']);

                if (! $variant->is_active) {
                    throw ValidationException::withMessages([
                        'items' => __('orders_payments.variant_inactive', ['sku' => $variant->sku]),
                    ]);
                }

                $qty = (int) $itemInput['qty'];
                $discount = (float) ($itemInput['discount_amount'] ?? 0);
                $lineTotal = ((float) $variant->sell_price * $qty) - $discount;

                $lineData[] = [
                    'variant' => $variant,
                    'qty' => $qty,
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                ];

                $subtotal += (float) $variant->sell_price * $qty;
                $totalCost += (float) $variant->cost_price * $qty;
            }

            $orderDiscount = (float) ($data['discount_amount'] ?? 0);
            $totalAmount = $subtotal - $orderDiscount;

            $paidAmount = collect($data['payments'])->sum('amount');
            if (round($paidAmount, 2) < round($totalAmount, 2)) {
                throw ValidationException::withMessages([
                    'payments' => __('orders_payments.payment_insufficient'),
                ]);
            }

            $cashPaid = collect($data['payments'])->where('method', 'cash')->sum('amount');
            $nonCashPaid = $paidAmount - $cashPaid;
            $changeAmount = max(0, $paidAmount - $totalAmount);
            // Kembalian hanya masuk akal dari uang tunai yang dibayarkan;
            // bila kembalian melebihi tunai yang diterima, ada kesalahan
            // input jumlah pembayaran.
            if ($changeAmount > $cashPaid + 0.01) {
                throw ValidationException::withMessages([
                    'payments' => __('orders_payments.change_exceeds_cash_received'),
                ]);
            }

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'event_id' => $session->event_id,
                'session_id' => $session->id,
                'customer_id' => $data['customer_id'] ?? null,
                'user_id' => $cashier->id,
                'channel' => 'offline',
                'subtotal' => $subtotal,
                'discount_amount' => $orderDiscount,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'status' => 'completed',
                'local_ref' => $data['local_ref'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lineData as $line) {
                $variant = $line['variant'];

                $order->items()->create([
                    'variant_id' => $variant->id,
                    'artist_id' => $variant->product->artist_id,
                    'sku_snapshot' => $variant->sku,
                    'name_snapshot' => $variant->product->name.' — '.$variant->variant_name,
                    'qty' => $line['qty'],
                    'cost_price' => $variant->cost_price,
                    'sell_price' => $variant->sell_price,
                    'discount_amount' => $line['discount'],
                    'line_total' => $line['line_total'],
                ]);

                $this->stockService->applyMovement(
                    variant: $variant,
                    type: 'sale',
                    qtyChange: -$line['qty'],
                    referenceType: 'order_item',
                    referenceId: $order->id,
                    userId: $cashier->id,
                );
            }

            foreach ($data['payments'] as $paymentInput) {
                $this->paymentRecorder->record($paymentInput, $order->id, null);
            }

            return $order->load(['items', 'payments.proofs']);
        });
    }

    public function void(Order $order, string $reason, User $user): Order
    {
        if ($order->status === 'voided') {
            throw ValidationException::withMessages(['status' => __('orders_payments.already_voided')]);
        }

        return DB::transaction(function () use ($order, $reason, $user) {
            foreach ($order->items as $item) {
                $variant = ProductVariant::lockForUpdate()->findOrFail($item->variant_id);
                $this->stockService->applyMovement(
                    variant: $variant,
                    type: 'return',
                    qtyChange: $item->qty, // dikembalikan
                    referenceType: 'order_item',
                    referenceId: $item->id,
                    userId: $user->id,
                );
            }

            $order->update(['status' => 'voided', 'void_reason' => $reason]);

            return $order->fresh(['items', 'payments']);
        });
    }

    /**
     * ASSUMPTION (Ambiguitas A8 di laporan): openapi-pos-mvp.yaml hanya
     * memberi CONTOH format "TRX-20261025-0001", tidak mendefinisikan
     * algoritma pastinya. Saya pilih: urutan reset harian, global lintas
     * event (bukan per-event), karena lebih sederhana dan order_number
     * hanya dipakai untuk tampilan struk/pencarian manusia, bukan kunci
     * bisnis. Perlu dikonfirmasi bila tim menginginkan reset per-event.
     */
    /**
     * 003-seed-demo-live — BUG YANG DITEMUKAN & DIPERBAIKI: `orders.
     * order_number` bersifat UNIQUE lintas SELURUH tabel (tidak ada
     * data_mode pada constraint-nya), tapi hitungan di sini sebelumnya
     * lewat `Order::whereDate(...)->count()` yang otomatis disaring
     * DataModeScope ke mode aktif saja. Akibatnya order DEMO dan LIVE
     * pada hari yang sama sama-sama mulai menghitung dari 0 dan
     * bertabrakan di nomor urut yang sama (mis. dua-duanya jadi
     * TRX-20260903-0001), gagal INSERT dengan galat integritas.
     * `withoutGlobalScope` di sini bukan kebocoran data lintas mode —
     * nomor struk memang harus unik lintas seluruh instalasi, terlepas
     * dari mode, sesuai constraint database itu sendiri.
     */
    private function generateOrderNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = Order::withoutGlobalScope(DataModeScope::class)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return sprintf('TRX-%s-%04d', $today, $countToday + 1);
    }
}
