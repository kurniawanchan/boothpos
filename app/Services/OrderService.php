<?php

namespace App\Services;

use App\Models\CashierSession;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
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
                'session_id' => 'Sesi kasir tidak dalam status terbuka.',
            ]);
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
                        'items' => "Varian {$variant->sku} tidak aktif.",
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
                    'payments' => 'Total pembayaran tidak menutup total transaksi.',
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
                    'payments' => 'Kembalian tidak dapat melebihi jumlah tunai yang diterima.',
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
            throw ValidationException::withMessages(['status' => 'Transaksi sudah dibatalkan sebelumnya.']);
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
    private function generateOrderNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = Order::whereDate('created_at', now()->toDateString())->count();

        return sprintf('TRX-%s-%04d', $today, $countToday + 1);
    }
}
