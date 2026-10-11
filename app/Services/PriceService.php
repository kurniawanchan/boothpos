<?php

namespace App\Services;

use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\MenuSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — SATU-SATUNYA penulis `product_variants.sell_price` (research.md D7). Setiap
 * perubahan harga dasar meninggalkan baris `price_history` di transaksi yang sama. `cost_price` BUKAN urusan
 * kelas ini (mengikuti BOM-selesai atau resep).
 *
 * Mode (research.md D2/D3):
 *  - rilis MATI dan tanggal berlaku hari ini/kosong → diterapkan seketika;
 *  - tanggal berlaku di MASA DEPAN (kedua mode) → disimpan sebagai `menu_change` dan diterapkan oleh
 *    ReleaseService::publishDue() saat tiba waktunya (tanpa penjadwal);
 *  - rilis AKTIF → `menu_change` menunggu rilis; selisih di atas ambang → `awaiting_approval` + Approval.
 * Sumber `release` dan `created` selalu diterapkan langsung (itulah jalur penerapan rilis dan pembuatan).
 */
class PriceService
{
    public function __construct(private ActivityLogger $log, private ApprovalService $approvals) {}

    /**
     * @param  array{effective_from?: string|null, batch_id?: int|null, audit?: bool, note?: string|null, release_id?: int|null, change_id?: int|null, approved_by?: int|null}  $o
     * @return array{changed: bool, applied: bool, pending: bool, requires_approval: bool, change: MenuChange|null, history: PriceHistory|null}
     *
     * @throws ValidationException
     */
    public function setBasePrice(ProductVariant $variant, float|string $newPrice, User $by, string $source = 'edit', array $o = []): array
    {
        $new = round((float) $newPrice, 2);
        if ($new < 0) {
            throw ValidationException::withMessages(['sell_price' => __('products.price_negative')]);
        }

        $old = round((float) $variant->sell_price, 2);
        $none = ['changed' => false, 'applied' => false, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => null];
        if ($new === $old) {
            return $none;
        }

        $direct = in_array($source, ['release', 'created'], true);
        $effective = $o['effective_from'] ?? null;
        $future = $effective !== null && $effective > BusinessDay::today();

        if (! $direct && (MenuSettings::releaseEnabled() || $future)) {
            return $this->queue($variant, $old, $new, $by, $source, $o);
        }

        $history = $this->apply($variant, $old, $new, $by, $source, $o);

        return ['changed' => true, 'applied' => true, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => $history];
    }

    /** Baris riwayat `created` untuk varian baru (dipanggil hook model ProductVariant::created; satu implementasi). */
    public function recordCreated(ProductVariant $variant): void
    {
        PriceHistory::create([
            'variant_id' => $variant->id, 'old_price' => null, 'new_price' => (float) $variant->sell_price, 'source' => 'created',
            'user_id' => auth()->id(), 'effective_at' => now(),
        ]);
    }

    /** Penerapan langsung: tulis harga, riwayat, dan (opsional) audit — satu transaksi. Juga dipakai ReleaseService. */
    public function apply(ProductVariant $variant, float $old, float $new, User $by, string $source, array $o = []): PriceHistory
    {
        return DB::transaction(function () use ($variant, $old, $new, $by, $source, $o) {
            $variant->forceFill(['sell_price' => $new])->save();

            $history = PriceHistory::create([
                'variant_id' => $variant->id, 'old_price' => $old, 'new_price' => $new, 'source' => $source,
                'user_id' => $by->id, 'approved_by' => $o['approved_by'] ?? null, 'effective_at' => now(),
                'release_id' => $o['release_id'] ?? null, 'change_id' => $o['change_id'] ?? null, 'note' => $o['note'] ?? null,
            ]);

            if (($o['audit'] ?? true) === true) {
                $this->log->log(
                    userId: $by->id, action: 'price_changed', entityType: 'ProductVariant', entityId: $variant->id,
                    description: "Harga {$variant->sku} {$old} → {$new} ({$source}).",
                    oldValues: ['sell_price' => $old], newValues: ['sell_price' => $new],
                );
            }

            return $history;
        });
    }

    private function queue(ProductVariant $variant, float $old, float $new, User $by, string $source, array $o): array
    {
        return DB::transaction(function () use ($variant, $old, $new, $by, $source, $o) {
            $variant->loadMissing('product');
            $needsApproval = MenuSettings::releaseEnabled() && $this->approvals->exceedsThreshold($old, $new);

            $change = MenuChange::create([
                'kind' => MenuChange::KIND_PRICE, 'subject_type' => 'variant', 'subject_id' => $variant->id,
                'payload' => [
                    'variant_id' => $variant->id, 'product_id' => $variant->product_id, 'product_name' => $variant->product?->name,
                    'variant_name' => $variant->variant_name, 'old_price' => $old, 'new_price' => $new, 'source' => $source,
                    'note' => $o['note'] ?? null,
                ],
                'effective_from' => $o['effective_from'] ?? null,
                'status' => $needsApproval ? MenuChange::AWAITING_APPROVAL : MenuChange::PENDING,
                'requested_by' => $by->id, 'batch_id' => $o['batch_id'] ?? null,
            ]);

            if ($needsApproval) {
                $approval = $this->approvals->requestForChange($change, $by, $old, $new);
                $change->update(['approval_id' => $approval->id]);
            }

            $this->log->log(
                userId: $by->id, action: 'menu_change_requested', entityType: 'ProductVariant', entityId: $variant->id,
                description: "Perubahan harga diajukan: {$old} → {$new}".($needsApproval ? ' (menunggu persetujuan)' : '').'.',
                newValues: ['change_id' => $change->id, 'source' => $source],
            );

            return ['changed' => true, 'applied' => false, 'pending' => true, 'requires_approval' => $needsApproval, 'change' => $change->fresh(), 'history' => null];
        });
    }
}
