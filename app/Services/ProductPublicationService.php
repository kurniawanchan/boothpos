<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Customer commerce (CONTRACT §6) — SATU-satunya penulis `published_at`
 * produk dan varian (kendali terbit ke etalase publik).
 *
 * Kenapa service tersendiri dan bukan bagian dari update produk: terbit
 * adalah keputusan membuka barang ke PUBLIK, digerbang aksi bernama
 * `products.publish`. Kolomnya sengaja bukan fillable dan tidak diterima
 * UpdateProductRequest / UpdateVariantRequest, jadi jalur lain tidak bisa
 * menyentuhnya tanpa sengaja.
 *
 * Tiap pemanggilan: satu transaksi, baris dikunci, dan baris activity_logs
 * ditulis DI DALAM transaksi yang sama (log tidak boleh mengklaim sesuatu
 * yang batal). Pemanggilan yang tidak mengubah apa pun (sudah terbit / sudah
 * ditarik) tidak menulis log dan tidak menggeser tanggal terbit pertama.
 *
 * Aturan yang sengaja TIDAK ditegakkan di sini: produk nonaktif, pre-order
 * atau tanpa stok tetap boleh diberi tanda terbit. Tampil-tidaknya di
 * etalase diputuskan saat dibaca oleh Product::scopeStorefrontVisible()
 * (satu definisi), bukan dibekukan saat tombol ditekan.
 */
class ProductPublicationService
{
    public const ACTION_PUBLISHED = 'storefront_published';

    public const ACTION_UNPUBLISHED = 'storefront_unpublished';

    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Menerbitkan produk. `$withVariants` ikut menerbitkan varian AKTIF yang
     * belum terbit; varian nonaktif dibiarkan, dan varian yang sudah terbit
     * tidak digeser tanggalnya.
     */
    public function publishProduct(Product $product, bool $withVariants, ?User $actor): Product
    {
        return DB::transaction(function () use ($product, $withVariants, $actor) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $now = now()->startOfSecond();
            $before = $locked->published_at;

            $productChanged = $this->isUnpublishedAt($before, $now);
            if ($productChanged) {
                $locked->forceFill(['published_at' => $now])->save();
            }

            $variantIds = [];
            if ($withVariants) {
                $variants = $locked->variants()
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '>', $now))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($variants as $variant) {
                    $variant->forceFill(['published_at' => $now])->save();
                    $variantIds[] = $variant->id;
                }
            }

            if ($productChanged || $variantIds !== []) {
                $this->activityLogger->log(
                    userId: $actor?->id,
                    action: self::ACTION_PUBLISHED,
                    entityType: 'Product',
                    entityId: $locked->id,
                    description: $variantIds === []
                        ? "Menerbitkan produk {$locked->name} ke etalase."
                        : "Menerbitkan produk {$locked->name} ke etalase beserta ".count($variantIds).' varian.',
                    oldValues: ['published_at' => $before?->toIso8601String()],
                    newValues: ['published_at' => $locked->published_at?->toIso8601String(), 'published_variant_ids' => $variantIds],
                );
            }

            return $locked;
        });
    }

    /**
     * Menarik produk dari etalase. Tanggal terbit VARIAN sengaja dibiarkan:
     * produk yang ditarik sudah menyembunyikan semuanya, dan menerbitkannya
     * lagi memulihkan susunan varian yang sama.
     */
    public function unpublishProduct(Product $product, ?User $actor): Product
    {
        return DB::transaction(function () use ($product, $actor) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $before = $locked->published_at;

            if ($before !== null) {
                $locked->forceFill(['published_at' => null])->save();

                $this->activityLogger->log(
                    userId: $actor?->id,
                    action: self::ACTION_UNPUBLISHED,
                    entityType: 'Product',
                    entityId: $locked->id,
                    description: "Menarik produk {$locked->name} dari etalase.",
                    oldValues: ['published_at' => $before->toIso8601String()],
                    newValues: ['published_at' => null],
                );
            }

            return $locked;
        });
    }

    /** Menerbitkan satu varian. Produknya tidak ikut terbit: itu keputusan terpisah. */
    public function publishVariant(ProductVariant $variant, ?User $actor): ProductVariant
    {
        return DB::transaction(function () use ($variant, $actor) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $now = now()->startOfSecond();
            $before = $locked->published_at;

            if ($this->isUnpublishedAt($before, $now)) {
                $locked->forceFill(['published_at' => $now])->save();

                $this->activityLogger->log(
                    userId: $actor?->id,
                    action: self::ACTION_PUBLISHED,
                    entityType: 'ProductVariant',
                    entityId: $locked->id,
                    description: "Menerbitkan varian {$locked->sku} ke etalase.",
                    oldValues: ['published_at' => $before?->toIso8601String()],
                    newValues: ['published_at' => $now->toIso8601String()],
                );
            }

            return $locked;
        });
    }

    public function unpublishVariant(ProductVariant $variant, ?User $actor): ProductVariant
    {
        return DB::transaction(function () use ($variant, $actor) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $before = $locked->published_at;

            if ($before !== null) {
                $locked->forceFill(['published_at' => null])->save();

                $this->activityLogger->log(
                    userId: $actor?->id,
                    action: self::ACTION_UNPUBLISHED,
                    entityType: 'ProductVariant',
                    entityId: $locked->id,
                    description: "Menarik varian {$locked->sku} dari etalase.",
                    oldValues: ['published_at' => $before->toIso8601String()],
                    newValues: ['published_at' => null],
                );
            }

            return $locked;
        });
    }

    /** Belum terbit: kosong, atau dijadwalkan di masa depan (menerbitkan = tampil sekarang). */
    private function isUnpublishedAt(?CarbonInterface $publishedAt, CarbonInterface $now): bool
    {
        return $publishedAt === null || $publishedAt->greaterThan($now);
    }
}
