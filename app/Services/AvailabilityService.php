<?php

namespace App\Services;

use App\Models\ItemAvailabilityMark;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\User;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — tanda "86" (habis): menandai dan membatalkan. SATU-SATUNYA penulis
 * `item_availability_marks`. Tanda berlaku sampai akhir HARI BISNIS (zona waktu toko + cut-off, 042) atau
 * sampai dibatalkan; kedaluwarsa hanya perbandingan waktu saat dibaca (research.md D6). Baris yang sudah ada
 * di order tidak disentuh — aturan "bisa dipesan" ada di MenuAvailability.
 *
 * Setiap tindakan menulis `activity_logs` di transaksi yang sama (Konstitusi IV).
 */
class AvailabilityService
{
    public function __construct(private ActivityLogger $log) {}

    /**
     * @throws ValidationException 422 alasan tidak valid; 409 item sudah didiskontinu / sudah ditandai
     */
    /**
     * @param  list<int>|null  $outletIds  null = tanda GLOBAL satu baris (perilaku lama); daftar = satu tanda per outlet
     */
    public function mark(Product $product, User $by, int $reasonCodeId, ?string $note = null, ?array $outletIds = null): ItemAvailabilityMark
    {
        if ($outletIds !== null && $outletIds === []) {
            throw ValidationException::withMessages(['outlet_ids' => __('products.outlet_ids_required')]);
        }

        $reason = ReasonCode::query()->active()->where('category', ReasonCode::SOLD_OUT)->find($reasonCodeId);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason_code_id' => __('products.reason_invalid')]);
        }

        return DB::transaction(function () use ($product, $by, $reason, $note, $outletIds) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->discontinued_at !== null) {
                throw ValidationException::withMessages(['product' => __('products.sold_out_discontinued')])->status(409);
            }
            $targets = $outletIds === null ? [null] : array_values(array_unique(array_map('intval', $outletIds)));
            foreach ($targets as $target) {
                // Global menandai semua outlet, jadi bentrok dengan tanda apa pun; tanda outlet hanya dengan yang berlaku di outlet itu.
                if (ItemAvailabilityMark::query()->active()->appliesTo($target)->where('product_id', $product->id)->exists()) {
                    throw ValidationException::withMessages(['product' => __('products.already_sold_out')])->status(409);
                }
            }

            [, $dayEnd] = BusinessDay::bounds(BusinessDay::today());

            $mark = null;
            foreach ($targets as $target) {
                $created = ItemAvailabilityMark::create([
                    'product_id' => $product->id,
                    'outlet_id' => $target,
                    'reason_code_id' => $reason->id,
                    'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                    'marked_by' => $by->id,
                    'marked_at' => now(),
                    'expires_at' => $dayEnd,
                ]);
                $mark ??= $created;
            }

            $this->log->log(
                userId: $by->id, action: 'item_sold_out', entityType: 'Product', entityId: $product->id,
                description: "Item {$product->name} ditandai habis (86): {$reason->code}.",
                newValues: ['reason' => $reason->code, 'note' => $mark->note, 'expires_at' => $dayEnd->toIso8601String(), 'outlet_ids' => $outletIds],
            );

            return $mark->load('reason');
        });
    }

    /**
     * Membatalkan SEMUA tanda aktif item itu.
     *
     * @throws ValidationException 409 bila tidak ada tanda aktif
     */
    /** @param  list<int>|null  $outletIds  null = semua tanda aktif; daftar = hanya tanda outlet-outlet itu (tanda global tidak disentuh) */
    public function undo(Product $product, User $by, ?array $outletIds = null): int
    {
        return DB::transaction(function () use ($product, $by, $outletIds) {
            $marks = ItemAvailabilityMark::query()->active()->where('product_id', $product->id)
                ->when($outletIds !== null, fn ($q) => $q->whereIn('outlet_id', array_map('intval', $outletIds) ?: [0]))
                ->lockForUpdate()->get();
            if ($marks->isEmpty()) {
                throw ValidationException::withMessages(['product' => __('products.not_sold_out')])->status(409);
            }

            ItemAvailabilityMark::whereIn('id', $marks->pluck('id'))->update(['cleared_at' => now(), 'cleared_by' => $by->id]);

            $this->log->log(
                userId: $by->id, action: 'item_sold_out_cleared', entityType: 'Product', entityId: $product->id,
                description: "Tanda habis (86) item {$product->name} dibatalkan.",
            );

            return $marks->count();
        });
    }

    /** Mengakhiri tanda aktif saat item didiskontinu (dipanggil layanan siklus hidup di transaksinya). */
    public function clearForDiscontinue(Product $product, User $by): void
    {
        ItemAvailabilityMark::query()->active()->where('product_id', $product->id)->update(['cleared_at' => now(), 'cleared_by' => $by->id]);
    }
}
