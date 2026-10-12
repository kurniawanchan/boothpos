<?php

namespace App\Support;

use App\Models\ItemAvailabilityMark;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — SATU-SATUNYA aturan "bisa dipesan?" (research.md D4). Dipakai checkout POS,
 * penambahan baris tab, daftar POS, dan polling ketersediaan; tidak ada jalur lain yang memeriksa
 * `is_active` untuk keperluan ini (Konstitusi I).
 *
 * Urutan alasan: varian nonaktif, produk hilang/nonaktif (termasuk diskontinu), lalu ditandai 86 (aktif,
 * belum berakhir). Baris yang SUDAH ada di order tidak pernah diubah oleh aturan ini — ia hanya menolak
 * penambahan baru.
 *
 * Catatan perilaku (diwarisi dari sebelum 043, dikunci OrderAvailabilityCharacterizationTest): dulu hanya
 * varian yang diperiksa; kini status produk induk ikut diperiksa, sehingga mendiskontinu produk benar-benar
 * menghalangi pesanan baru.
 *
 * Tahap B menambahkan outlet pada pemeriksaan tanda 86 (outlet pesanan), tanpa mengubah penanda tangan ini.
 */
class MenuAvailability
{
    public const VARIANT_INACTIVE = 'variant_inactive';

    public const PRODUCT_INACTIVE = 'product_inactive';

    public const SOLD_OUT = 'sold_out';

    /** 045 Stage 3 — produk aktif tetapi outlet ini tidak membawanya. */
    public const NOT_CARRIED = 'not_carried';

    /** Alasan tidak bisa dipesan, atau null bila bisa. Varian harus dimuat dengan relasi `product`. */
    public static function unavailableReason(ProductVariant $variant, ?int $outletId = null): ?string
    {
        if (! $variant->is_active) {
            return self::VARIANT_INACTIVE;
        }

        $product = $variant->product;
        if ($product === null || ! $product->is_active || $product->discontinued_at !== null) {
            return self::PRODUCT_INACTIVE;
        }

        // 045 Stage 3 — outlet pesanan: produk harus dibawa outlet itu dan tidak ditandai 86 di sana (atau global).
        if ($outletId !== null && ! app(\App\Services\OutletMenuService::class)->isCarried($product->id, $outletId)) {
            return self::NOT_CARRIED;
        }

        if (ItemAvailabilityMark::query()->active()->appliesTo($outletId)->where('product_id', $product->id)->exists()) {
            return self::SOLD_OUT;
        }

        return null;
    }

    public static function isOrderable(ProductVariant $variant, ?int $outletId = null): bool
    {
        return self::unavailableReason($variant, $outletId) === null;
    }

    /**
     * @param  string  $field  kunci galat validasi tempat pesan ditampilkan (`items` untuk checkout, `variant_id` untuk tab)
     *
     * @throws ValidationException
     */
    public static function assertOrderable(ProductVariant $variant, string $field = 'items', ?int $outletId = null): void
    {
        $reason = self::unavailableReason($variant, $outletId);
        if ($reason === null) {
            return;
        }

        $message = $reason === self::VARIANT_INACTIVE
            ? __('orders_payments.variant_inactive', ['sku' => $variant->sku])
            : __('orders_payments.item_unavailable', ['sku' => $variant->sku, 'reason' => __('orders_payments.unavailable_'.$reason)]);

        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Id produk yang sedang ditandai 86 (aktif) — untuk daftar/poll tanpa satu query per kartu.
     *
     * @return list<int>
     */
    public static function soldOutProductIds(?int $outletId = null): array
    {
        return ItemAvailabilityMark::query()->active()->appliesTo($outletId)->pluck('product_id')->unique()->values()->all();
    }
}
