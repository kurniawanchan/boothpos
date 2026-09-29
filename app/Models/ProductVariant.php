<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — 'sku' semula dikeluarkan dari
     * $fillable dengan niat "permanen setelah dibuat", tapi SATU-SATUNYA
     * jalur pembuatan varian di seluruh kodebase ini (ProductController::
     * store()/storeVariant(), plus setiap factory/test) menulis 'sku'
     * lewat mass-assignment create(). Akibatnya insert selalu gagal 500
     * "sku doesn't have a default value". Proteksi terhadap perubahan SKU
     * lewat UPDATE sudah cukup di lapisan validasi: UpdateVariantRequest
     * tidak punya rule 'sku' sama sekali, jadi $variant->update($request->
     * validated()) tidak akan pernah bisa menyentuhnya — $fillable tidak
     * perlu memblokir CREATE juga. 'current_stock' ditambahkan dengan
     * alasan sama: test/factory menyetel nilai stok awal lewat create().
     * 'product_id' TIDAK perlu ditambah — HasMany::create() dari relasi
     * $product->variants() menyuntikkan foreign key lewat setAttribute()
     * langsung, di luar mekanisme $fillable sama sekali.
     */
    protected $fillable = [
        'sku',
        'image_path',
        'variant_name',
        'cost_price',
        'sell_price',
        'current_stock',
        'low_stock_alert',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'current_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bomLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductVariantBomLine::class);
    }

    public function isLowStock(): bool
    {
        return $this->low_stock_alert !== null && $this->current_stock <= $this->low_stock_alert;
    }

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — accessor ini TIDAK PERNAH ada
     * sebelumnya; `PreorderController::present()` sudah lama membaca
     * `$variant?->image_url` seolah-olah itu accessor sungguhan, padahal
     * Eloquent hanya mengembalikan null diam-diam untuk atribut yang tidak
     * ada (bukan error) — jadi gambar varian di form Edit Pre-order selalu
     * kosong (placeholder), ditemukan lewat verifikasi browser sungguhan.
     * `ProductController::lookupVariants()` sudah lama menghitung URL yang
     * SAMA secara manual (fallback ke gambar produk kalau varian sendiri
     * tidak punya) — dipindah ke sini sebagai accessor SUNGGUHAN supaya
     * setiap pemanggil `->image_url` di masa depan otomatis benar, bukan
     * menduplikasi logika fallback yang sama lagi di tempat lain.
     */
    public function getImageUrlAttribute(): ?string
    {
        $service = app(\App\Services\ImageUploadService::class);

        return $service->url($this->image_path) ?? $service->url($this->product?->image_path);
    }
}
