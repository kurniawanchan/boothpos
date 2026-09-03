<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Material;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 006-purchase-order-and-ops (US1). Status transition guard mirrors
 * PreorderService::transitionStatus() exactly (research.md R5); the
 * Received transition's material-stock effect mirrors that method's own
 * 'arrived' branch, but calling MaterialStockService instead of
 * StockService (research.md R4).
 */
class PurchaseOrderService
{
    public function __construct(
        private MaterialStockService $materialStockService,
        private PaymentRecorder $paymentRecorder,
        private ActivityLogger $activityLogger,
    ) {}

    public function create(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            $subtotal = 0;
            $lineData = [];

            foreach ($data['items'] as $itemInput) {
                $qty = (float) $itemInput['qty'];
                $unitPrice = (float) $itemInput['unit_price'];
                $lineTotal = $qty * $unitPrice;
                $lineData[] = $itemInput + ['line_total' => $lineTotal];
                $subtotal += $lineTotal;
            }

            $po = PurchaseOrder::create([
                'po_number' => $this->generatePoNumber(),
                'vendor_id' => $data['vendor_id'],
                'status' => 'draft',
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($lineData as $line) {
                $po->items()->create([
                    'line_type' => $line['line_type'],
                    'material_id' => $line['material_id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $this->activityLogger->log(
                userId: $user->id,
                action: 'created',
                entityType: 'PurchaseOrder',
                entityId: $po->id,
                description: "Membuat purchase order {$po->po_number}.",
                newValues: $po->only($po->getFillable()),
            );

            return $po->fresh(['items', 'vendor']);
        });
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        if (array_key_exists('items', $data) && $po->status !== 'draft') {
            throw ValidationException::withMessages([
                'items' => __('purchase_orders.items_locked_after_draft'),
            ]);
        }

        return DB::transaction(function () use ($po, $data) {
            if (array_key_exists('items', $data)) {
                $po->items()->delete();
                $subtotal = 0;

                foreach ($data['items'] as $itemInput) {
                    $qty = (float) $itemInput['qty'];
                    $unitPrice = (float) $itemInput['unit_price'];
                    $lineTotal = $qty * $unitPrice;
                    $subtotal += $lineTotal;

                    $po->items()->create([
                        'line_type' => $itemInput['line_type'],
                        'material_id' => $itemInput['material_id'] ?? null,
                        'product_id' => $itemInput['product_id'] ?? null,
                        'description' => $itemInput['description'] ?? null,
                        'qty' => $itemInput['qty'],
                        'unit_price' => $itemInput['unit_price'],
                        'line_total' => $lineTotal,
                    ]);
                }

                $po->update(['subtotal' => $subtotal, 'total_amount' => $subtotal]);
            }

            $po->update(array_intersect_key($data, array_flip(['vendor_id', 'notes'])));

            return $po->fresh(['items', 'vendor']);
        });
    }

    public function delete(PurchaseOrder $po, User $user): void
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('purchase_orders.only_draft_deletable'),
            ]);
        }

        DB::transaction(function () use ($po, $user) {
            $snapshot = $po->only($po->getFillable());
            $po->delete();

            $this->activityLogger->log(
                userId: $user->id,
                action: 'deleted',
                entityType: 'PurchaseOrder',
                entityId: $po->id,
                description: "Menghapus purchase order {$po->po_number}.",
                oldValues: $snapshot,
            );
        });
    }

    public function transitionStatus(PurchaseOrder $po, string $newStatus, ?string $cancelReason, User $user): PurchaseOrder
    {
        if (! $po->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => __('purchase_orders.invalid_status_transition', ['from' => $po->status, 'to' => $newStatus]),
            ]);
        }

        return DB::transaction(function () use ($po, $newStatus, $cancelReason, $user) {
            $oldStatus = $po->status;

            if ($newStatus === 'received') {
                foreach ($po->items as $item) {
                    if ($item->line_type !== 'material' || ! $item->material_id) {
                        continue;
                    }

                    $material = Material::lockForUpdate()->findOrFail($item->material_id);
                    $this->materialStockService->applyMovement(
                        material: $material,
                        type: 'purchase',
                        qtyChange: (float) $item->qty,
                        referenceType: 'purchase_order_item',
                        referenceId: $item->id,
                        userId: $user->id,
                    );
                }
            }

            $timestampColumn = match ($newStatus) {
                'ordered' => 'ordered_at',
                'received' => 'received_at',
                'paid' => 'paid_at',
                'cancelled' => 'cancelled_at',
                default => null,
            };

            $po->update([
                'status' => $newStatus,
                'cancel_reason' => $newStatus === 'cancelled' ? $cancelReason : $po->cancel_reason,
                ...($timestampColumn ? [$timestampColumn => now()] : []),
            ]);

            $this->activityLogger->log(
                userId: $user->id,
                action: 'status_changed',
                entityType: 'PurchaseOrder',
                entityId: $po->id,
                description: "Purchase order {$po->po_number}: {$oldStatus} -> {$newStatus}.",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
            );

            return $po->fresh(['items', 'vendor', 'payments']);
        });
    }

    public function recordPayment(PurchaseOrder $po, array $input, User $user): PurchaseOrder
    {
        if ($po->status !== 'received' && $po->status !== 'paid') {
            throw ValidationException::withMessages([
                'status' => __('purchase_orders.payment_requires_received'),
            ]);
        }

        return DB::transaction(function () use ($po, $input, $user) {
            $this->paymentRecorder->record($input, null, null, $po->id);

            $paidAmount = (float) $po->paidAmount();

            if ($paidAmount >= (float) $po->total_amount && $po->status === 'received') {
                $po->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $po->fresh(['items', 'vendor', 'payments']);
        });
    }

    /**
     * Sama seperti OrderService::generateOrderNumber()/PreorderService::
     * generateNumber(): po_number unik lintas SELURUH tabel, jadi hitungannya
     * harus lintas mode juga (lihat CLAUDE.md "Seed data dan DEMO/LIVE mode").
     */
    private function generatePoNumber(): string
    {
        $today = now()->format('Ymd');
        $countToday = PurchaseOrder::withoutGlobalScope(DataModeScope::class)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return sprintf('PO-%s-%04d', $today, $countToday + 1);
    }
}
