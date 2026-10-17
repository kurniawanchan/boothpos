<?php

namespace App\Services;

use App\Models\Concerns\DataModeScope;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ProductSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SKU yang diketik pemilik (spec 045).
 *
 * Pemilik hanya mengetik NOMOR URUT; kode produk di depannya tetap. Server yang
 * menyusun SKU dari kode yang tersimpan, jadi klien tidak pernah bisa mengganti
 * kode lewat kolom ini. Aturan: hanya digit, minimal 1, nol di depan hilang,
 * SKU lengkap maksimal ProductSegment::MAX_SKU_LENGTH karakter (spec 046, lebar kolom), dan unik di seluruh toko
 * (termasuk varian yang dihapus dan kedua mode DEMO/LIVE).
 *
 * Setiap perubahan ditulis ke activity log dalam transaksi yang sama, dan
 * menandai SKU sebagai diketik (sku_is_typed) supaya ganti segmen tidak
 * menimpanya (Q2 A).
 */
class VariantSkuService
{
    public function __construct(
        private ProductCodeGenerator $codeGenerator,
        private ActivityLogger $activityLogger,
    ) {}

    public function setSequence(ProductVariant $variant, ?string $raw, ?User $user): void
    {
        if ($raw === null || trim($raw) === '') {
            return;
        }

        DB::transaction(function () use ($variant, $raw, $user) {
            $locked = ProductVariant::withTrashed()->whereKey($variant->id)->lockForUpdate()->firstOrFail();

            $number = $this->sequenceOrFail($raw);
            $candidate = $this->codeGenerator->variantSku($this->codeOf($locked), $number);

            if ($candidate === $locked->sku) {
                return;
            }

            $this->assertAvailable($candidate, $locked->id);

            $old = $locked->sku;
            $locked->update(['sku' => $candidate, 'sku_is_typed' => true]);

            $this->activityLogger->log(
                userId: $user?->id,
                action: 'sku_changed',
                entityType: 'ProductVariant',
                entityId: $locked->id,
                description: "Mengubah SKU {$old} menjadi {$candidate}.",
                oldValues: ['sku' => $old],
                newValues: ['sku' => $candidate],
            );
        });
    }

    /**
     * Seluruh perubahan nomor urut satu produk SEKALIGUS (spec 045, US1 — geser nomor).
     *
     * Per varian satu-satu tidak bisa: menaikkan `…-10` ke `…-11` gagal selama `…-11`
     * masih milik varian lain yang juga akan digeser. Karena itu: (1) semua nomor
     * baru dihitung dan diperiksa sebagai SATU keadaan akhir (tidak ada yang kembar di
     * dalam produk, tidak bentrok dengan SKU lain di toko, termasuk yang dihapus dan
     * kedua mode); (2) semua baris dipindah ke penanda sementara lalu ke nomor akhir
     * dalam satu transaksi. Galat apa pun membatalkan seluruh perubahan.
     *
     * @param  array<int, array{variant_id: int, sequence: ?string}>  $items
     */
    public function setSequences(Product $product, array $items, ?User $user): void
    {
        DB::transaction(function () use ($product, $items, $user) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            // Hanya varian yang masih ada; yang dihapus tidak bisa diberi nomor (spec 045).
            $all = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 1) Hitung SKU baru tiap baris yang diubah.
            $changes = [];
            foreach ($items as $item) {
                $raw = trim((string) ($item['sequence'] ?? ''));
                $variantId = (int) $item['variant_id'];
                if ($raw === '') {
                    continue;
                }

                $variant = $all->get($variantId);
                if ($variant === null) {
                    throw ValidationException::withMessages(['sku' => ['Varian tidak termasuk produk ini.']]);
                }

                $number = $this->codeGenerator->normaliseSequence($raw);
                if ($number === null) {
                    throw ValidationException::withMessages([
                        "sequences.{$variantId}" => ['Nomor urut harus angka lebih dari 0 (hanya digit).'],
                    ]);
                }

                $candidate = $this->codeGenerator->variantSku($this->codeOf($variant), $number);
                if (strlen($candidate) > ProductSegment::MAX_SKU_LENGTH) {
                    throw ValidationException::withMessages([
                        "sequences.{$variantId}" => ['SKU lengkap melebihi 20 karakter. Gunakan nomor urut yang lebih pendek.'],
                    ]);
                }

                if ($candidate !== $variant->sku) {
                    $changes[$variantId] = ['old' => $variant->sku, 'new' => $candidate];
                }
            }

            if ($changes === []) {
                return;
            }

            // 2) Keadaan akhir produk harus unik di dalam produk.
            $finalOwner = [];
            foreach ($all as $id => $variant) {
                $final = $changes[$id]['new'] ?? $variant->sku;
                if (isset($finalOwner[$final])) {
                    // Galat ditempelkan pada baris yang DIUBAH, bukan pada varian yang tetap.
                    $other = $finalOwner[$final];
                    $key = isset($changes[$id]) ? $id : $other;
                    $message = isset($changes[$id], $changes[$other])
                        ? "Nomor SKU {$final} dipakai dua varian sekaligus."
                        : "Nomor SKU {$final} sudah dipakai.";

                    throw ValidationException::withMessages(["sequences.{$key}" => [$message]]);
                }
                $finalOwner[$final] = $id;
            }

            // 3) Tidak bentrok dengan SKU lain di toko (baris yang sedang diubah dikecualikan).
            foreach ($changes as $id => $change) {
                $taken = ProductVariant::withoutGlobalScope(DataModeScope::class)
                    ->where('is_active', true)
                    ->where('sku', $change['new'])
                    ->whereNotIn('id', array_keys($changes))
                    ->exists();

                if ($taken) {
                    throw ValidationException::withMessages([
                        "sequences.{$id}" => ["Nomor SKU {$change['new']} sudah dipakai."],
                    ]);
                }
            }

            // 4) Tulis: penanda sementara dulu (agar nomor lama bisa lepas), lalu nomor akhir.
            foreach (array_keys($changes) as $id) {
                ProductVariant::withTrashed()->whereKey($id)->update(['sku' => '#'.$id]);
            }

            foreach ($changes as $id => $change) {
                ProductVariant::withTrashed()->whereKey($id)->update([
                    'sku' => $change['new'],
                    'sku_is_typed' => true,
                ]);

                $this->activityLogger->log(
                    userId: $user?->id,
                    action: 'sku_changed',
                    entityType: 'ProductVariant',
                    entityId: $id,
                    description: "Mengubah SKU {$change['old']} menjadi {$change['new']}.",
                    oldValues: ['sku' => $change['old']],
                    newValues: ['sku' => $change['new']],
                );
            }
        });
    }

    /**
     * SKU untuk varian BARU yang nomornya diketik di formulir. Blank → null, artinya
     * SKU dibuat server seperti biasa (nextVariantSku).
     */
    public function skuForNewVariant(Product $product, ?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $candidate = $this->codeGenerator->variantSku($product->code_prefix, $this->sequenceOrFail($raw));
        $this->assertAvailable($candidate, null);

        return $candidate;
    }

    private function sequenceOrFail(string $raw): int
    {
        $number = $this->codeGenerator->normaliseSequence($raw);

        if ($number === null) {
            throw ValidationException::withMessages([
                'sku' => ['Nomor urut harus angka lebih dari 0 (hanya digit).'],
            ]);
        }

        return $number;
    }

    /** Kode SKU saat ini: bagian sebelum tanda hubung terakhir; SKU lama tanpa tanda hubung memakai kode produk. */
    private function codeOf(ProductVariant $variant): string
    {
        return str_contains($variant->sku, '-')
            ? Str::beforeLast($variant->sku, '-')
            : (string) $variant->product->code_prefix;
    }

    private function assertAvailable(string $sku, ?int $exceptId): void
    {
        if (strlen($sku) > ProductSegment::MAX_SKU_LENGTH) {
            throw ValidationException::withMessages([
                'sku' => ['SKU lengkap melebihi 20 karakter. Gunakan nomor urut yang lebih pendek.'],
            ]);
        }

        // withoutGlobalScope: SKU unik lintas DEMO dan LIVE (sama seperti code_prefix).
        // Hanya varian AKTIF yang menahan nomor (spec 045, FR-016): nonaktif/dihapus tidak.
        $taken = ProductVariant::withoutGlobalScope(DataModeScope::class)
            ->where('is_active', true)
            ->where('sku', $sku)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'sku' => ["Nomor SKU {$sku} sudah dipakai."],
            ]);
        }
    }
}
