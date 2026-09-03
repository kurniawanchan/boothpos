<?php

namespace App\Services;

use App\Models\Preorder;
use App\Models\ProductVariant;
use App\Models\User;
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

    private function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = Preorder::whereDate('created_at', now()->toDateString())->count();
        return sprintf('PO-%s-%04d', $today, $countToday + 1);
    }
}
