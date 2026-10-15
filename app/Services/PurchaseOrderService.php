<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Material;
use App\Models\ProductVariantBomLine;
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
                'artist_id' => $data['artist_id'],
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

            return $this->reload($po);
        });
    }

    public function update(PurchaseOrder $po, array $data, ?User $user = null): PurchaseOrder
    {
        if (array_key_exists('items', $data) && $po->status !== 'draft') {
            throw ValidationException::withMessages([
                'items' => __('purchase_orders.items_locked_after_draft'),
            ]);
        }

        return DB::transaction(function () use ($po, $data, $user) {
            // 035-po-row-actions (FR-008) — keadaan SEBELUM perubahan untuk log
            // `purchase_order_updated` (vendor dan baris; seller punya log sendiri).
            $before = [
                'vendor_id' => $po->vendor_id,
                'total_amount' => (float) $po->total_amount,
                'lines' => $po->items()->count(),
            ];

            $this->applySellerChange($po, $data, $user);

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

            $po->update(array_intersect_key($data, array_flip(['vendor_id', 'notes', 'artist_id'])));

            $vendorChanged = array_key_exists('vendor_id', $data) && (int) $data['vendor_id'] !== (int) $before['vendor_id'];

            // Hanya perubahan bermakna finansial yang dicatat: vendor berganti atau
            // baris ditulis ulang. Hanya-catatan tidak (tanpa dampak uang); seller
            // sudah dicatat applySellerChange() — tidak digandakan di sini.
            if ($vendorChanged || array_key_exists('items', $data)) {
                $po->refresh();

                $this->activityLogger->log(
                    userId: $user?->id,
                    action: 'purchase_order_updated',
                    entityType: 'PurchaseOrder',
                    entityId: $po->id,
                    description: "Mengubah purchase order {$po->po_number}.",
                    oldValues: $before,
                    newValues: [
                        'vendor_id' => $po->vendor_id,
                        'total_amount' => (float) $po->total_amount,
                        'lines' => $po->items()->count(),
                    ],
                );
            }

            return $this->reload($po);
        });
    }

    /**
     * Muat ulang PO dengan SEMUA relasi yang dibaca PurchaseOrderController::
     * present() (seller, jumlah pemakaian BOM per baris, pembayaran) —
     * present() memakai relationLoaded(), jadi relasi yang lupa dimuat
     * menghilang diam-diam dari respons, bukan galat.
     */
    private function reload(PurchaseOrder $po, bool $withPayments = false): PurchaseOrder
    {
        return $po->fresh(array_filter([
            'items' => fn ($q) => $q->withCount('bomLines'),
            'vendor',
            'artist',
            $withPayments ? 'payments' : null,
        ]));
    }

    /**
     * 034-seller-po-bom — menetapkan seller PO lama (tanpa seller) selalu
     * boleh; MENGUBAH seller ditolak (409 lewat controller) bila ada baris
     * BOM yang sudah memakai salah satu baris PO ini, karena BOM itu akan
     * melanggar aturan "hanya PO milik seller varian". Pengecekan tidak
     * bergantung mode DEMO/LIVE aktif (withoutGlobalScopes): kuncinya harus
     * berlaku di mana pun baris BOM itu berada. Log ditulis di transaksi
     * yang sama dengan perubahannya.
     */
    private function applySellerChange(PurchaseOrder $po, array $data, ?User $user): void
    {
        if (! array_key_exists('artist_id', $data) || (int) $data['artist_id'] === (int) $po->artist_id) {
            return;
        }

        $oldArtistId = $po->artist_id;

        if ($oldArtistId !== null) {
            $inUse = ProductVariantBomLine::withoutGlobalScopes()
                ->whereIn('purchase_order_item_id', $po->items()->select('id'))
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    'artist_id' => __('bom.purchase_order_seller_in_use'),
                ]);
            }
        }

        $this->activityLogger->log(
            userId: $user?->id,
            action: $oldArtistId === null ? 'purchase_order_seller_assigned' : 'purchase_order_seller_changed',
            entityType: 'PurchaseOrder',
            entityId: $po->id,
            description: "Seller purchase order {$po->po_number}: ".($oldArtistId ?? 'kosong')." -> {$data['artist_id']}.",
            oldValues: ['artist_id' => $oldArtistId],
            newValues: ['artist_id' => (int) $data['artist_id']],
        );
    }

    public function delete(PurchaseOrder $po, User $user): void
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => __('purchase_orders.only_draft_deletable'),
            ]);
        }

        // 035-po-row-actions (FR-011) — penjagaan eksplisit, TIDAK bergantung pada
        // aturan transisi status: draft normalnya belum punya pembayaran dan belum
        // bisa jadi sumber BOM, tetapi "tidak boleh dihapus apa pun keadaannya"
        // harus berlaku walau datanya dibuat lewat jalur lain. Pengecekan BOM tidak
        // bergantung mode DEMO/LIVE aktif (withoutGlobalScopes), sama seperti
        // applySellerChange().
        if ($po->payments()->exists()) {
            throw ValidationException::withMessages(['payments' => __('purchase_orders.payment_blocks_delete')]);
        }

        if (ProductVariantBomLine::withoutGlobalScopes()->whereIn('purchase_order_item_id', $po->items()->select('id'))->exists()) {
            throw ValidationException::withMessages(['items' => __('purchase_orders.bom_blocks_delete')]);
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

            return $this->reload($po, withPayments: true);
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

            return $this->reload($po, withPayments: true);
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
