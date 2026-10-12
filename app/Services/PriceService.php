<?php

namespace App\Services;

use App\Models\MenuChange;
use App\Models\PriceHistory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantOutletPrice;
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

    /**
     * 045 Stage 3 — harga yang berlaku di sebuah outlet: harga khusus outlet bila ada, selain itu harga dasar.
     * Satu-satunya resolver harga per outlet (Constitution I); `outletId` null = harga dasar.
     */
    public function priceFor(ProductVariant $variant, ?int $outletId): float
    {
        if ($outletId !== null) {
            $override = VariantOutletPrice::query()->where('variant_id', $variant->id)->where('outlet_id', $outletId)->value('price');
            if ($override !== null) {
                return round((float) $override, 2);
            }
        }

        return round((float) $variant->sell_price, 2);
    }

    /**
     * 045 Stage 3 — membuat objek varian di MEMORI membawa harga efektif outlet, supaya seluruh perhitungan hilir
     * (total baris, resolver modifier, snapshot item) memakai SATU harga tanpa diubah satu per satu. Nilai asli
     * disinkronkan, jadi atribut ini TIDAK dianggap berubah dan tidak pernah tertulis ke `sell_price` dasar
     * oleh save() berikutnya (mis. pembaruan stok).
     */
    public function applyOutletPrice(ProductVariant $variant, ?int $outletId): ProductVariant
    {
        $variant->setAttribute('sell_price', number_format($this->priceFor($variant, $outletId), 2, '.', ''));
        $variant->syncOriginalAttribute('sell_price');

        return $variant;
    }

    /**
     * 045 Stage 3 — menetapkan harga khusus outlet; `null` menghapusnya (kembali mengikuti harga dasar).
     * Satu-satunya penulis `variant_outlet_prices`, dengan baris price_history ber-outlet dan audit di transaksi yang
     * sama. Rilis mati: diterapkan SEKETIKA. Rilis aktif: menjadi `menu_change` (payload membawa outlet_id) yang
     * menunggu rilis, dengan persetujuan bila selisih melewati ambang — jalur yang sama dengan harga dasar.
     *
     * @return array{changed: bool, applied: bool, pending: bool, requires_approval: bool, change: MenuChange|null, history: PriceHistory|null}
     *
     * @throws ValidationException
     */
    public function setOutletPrice(ProductVariant $variant, int $outletId, float|string|null $price, User $by, string $source = 'edit', ?string $note = null, array $o = []): array
    {
        $new = $price === null ? null : round((float) $price, 2);
        if ($new !== null && $new < 0) {
            throw ValidationException::withMessages(['price' => __('products.price_negative')]);
        }

        // 045 Stage 3 — dengan rilis menu aktif harga outlet mengikuti jalur yang SAMA dengan harga dasar: menjadi
        // `menu_change` (payload membawa outlet_id) yang menunggu rilis, dan selisih di atas ambang menunggu persetujuan.
        // Sumber `release` adalah penerapannya sendiri, jadi selalu langsung.
        if (MenuSettings::releaseEnabled() && $source !== 'release') {
            return $this->queueOutletPrice($variant, $outletId, $new, $by, $source, $note, $o);
        }

        return $this->writeOutletPrice($variant, $outletId, $new, $by, $source, $note, $o);
    }

    /**
     * Penulisan langsung harga khusus outlet (satu transaksi: baris harga, riwayat, audit). Dipakai jalur biasa
     * (rilis mati) dan oleh PriceChangeApplier saat rilis diterbitkan. `price` null = hapus harga khusus.
     *
     * @param  array{release_id?: int|null, change_id?: int|null, approved_by?: int|null}  $o
     */
    public function writeOutletPrice(ProductVariant $variant, int $outletId, ?float $new, User $by, string $source = 'edit', ?string $note = null, array $o = []): array
    {
        app(\App\Support\OutletPriceBook::class)->flush();

        return DB::transaction(function () use ($variant, $outletId, $new, $by, $source, $note, $o) {
            $row = VariantOutletPrice::query()->where('variant_id', $variant->id)->where('outlet_id', $outletId)->lockForUpdate()->first();
            $old = $row === null ? null : round((float) $row->price, 2);
            $none = ['changed' => false, 'applied' => false, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => null];

            if ($old === $new) {
                return $none;
            }

            if ($new === null) {
                $row->delete();
            } elseif ($row === null) {
                VariantOutletPrice::create(['variant_id' => $variant->id, 'outlet_id' => $outletId, 'price' => $new]);
            } else {
                $row->update(['price' => $new]);
            }

            // Riwayat mencatat harga EFEKTIF di outlet itu: sebelum/sesudah, dengan harga dasar sebagai pengganti "tanpa harga khusus".
            $base = round((float) $variant->sell_price, 2);
            $history = PriceHistory::create([
                'variant_id' => $variant->id, 'outlet_id' => $outletId, 'old_price' => $old ?? $base, 'new_price' => $new ?? $base,
                'source' => $source, 'user_id' => $by->id, 'approved_by' => $o['approved_by'] ?? null, 'effective_at' => now(),
                'release_id' => $o['release_id'] ?? null, 'change_id' => $o['change_id'] ?? null, 'note' => $note,
            ]);

            $this->log->log(
                userId: $by->id, action: 'outlet_price_changed', entityType: 'ProductVariant', entityId: $variant->id,
                description: "Harga {$variant->sku} di outlet #{$outletId}: ".($old ?? 'dasar').' → '.($new ?? 'dasar').'.',
                oldValues: ['outlet_id' => $outletId, 'price' => $old], newValues: ['outlet_id' => $outletId, 'price' => $new],
            );

            return ['changed' => true, 'applied' => true, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => $history];
        });
    }

    private function queueOutletPrice(ProductVariant $variant, int $outletId, ?float $new, User $by, string $source, ?string $note, array $o): array
    {
        return DB::transaction(function () use ($variant, $outletId, $new, $by, $source, $note, $o) {
            $variant->loadMissing('product');
            $overrideNow = VariantOutletPrice::query()->where('variant_id', $variant->id)->where('outlet_id', $outletId)->value('price');
            $overrideNow = $overrideNow === null ? null : round((float) $overrideNow, 2);
            $none = ['changed' => false, 'applied' => false, 'pending' => false, 'requires_approval' => false, 'change' => null, 'history' => null];
            if ($overrideNow === $new) {
                return $none;
            }

            $base = round((float) $variant->sell_price, 2);
            $old = $overrideNow ?? $base;
            $newEffective = $new ?? $base;
            $needsApproval = $this->approvals->exceedsThreshold($old, $newEffective);
            $outlet = \App\Models\Outlet::query()->find($outletId, ['id', 'code']);

            $change = MenuChange::create([
                'kind' => MenuChange::KIND_PRICE, 'subject_type' => 'variant', 'subject_id' => $variant->id,
                'payload' => [
                    'variant_id' => $variant->id, 'product_id' => $variant->product_id, 'product_name' => $variant->product?->name,
                    'variant_name' => $variant->variant_name, 'old_price' => $old, 'new_price' => $newEffective, 'new_override' => $new,
                    'outlet_id' => $outletId, 'outlet_code' => $outlet?->code, 'source' => $source, 'note' => $note,
                ],
                'effective_from' => $o['effective_from'] ?? null,
                'status' => $needsApproval ? MenuChange::AWAITING_APPROVAL : MenuChange::PENDING,
                'requested_by' => $by->id, 'batch_id' => $o['batch_id'] ?? null,
            ]);

            if ($needsApproval) {
                $approval = $this->approvals->requestForChange($change, $by, $old, $newEffective);
                $change->update(['approval_id' => $approval->id]);
            }

            $this->log->log(
                userId: $by->id, action: 'menu_change_requested', entityType: 'ProductVariant', entityId: $variant->id,
                description: "Perubahan harga outlet {$outlet?->code} diajukan: {$old} → {$newEffective}".($needsApproval ? ' (menunggu persetujuan)' : '').'.',
                newValues: ['change_id' => $change->id, 'source' => $source, 'outlet_id' => $outletId],
            );

            return ['changed' => true, 'applied' => false, 'pending' => true, 'requires_approval' => $needsApproval, 'change' => $change->fresh(), 'history' => null];
        });
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
