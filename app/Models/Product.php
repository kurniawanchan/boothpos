<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasDataMode, HasFactory, SoftDeletes;

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — 'code_prefix' dan 'product_segment'
     * awalnya sengaja dikeluarkan dari $fillable dengan niat mencegah
     * mass-assignment dari input klien. Niatnya benar, tapi caranya salah:
     * ProductController::store() sendiri butuh menulis KEDUA kolom ini
     * lewat Product::create() (nilainya berasal dari ProductCodeGenerator,
     * bukan dari klien) — akibatnya INSERT selalu gagal 500 "code_prefix
     * doesn't have a default value" karena Eloquent diam-diam membuang
     * kedua field itu. Proteksi sesungguhnya terhadap input klien sudah
     * cukup di lapisan validasi (StoreProductRequest tidak punya rule
     * 'code_prefix' sama sekali, dan UpdateProductRequest sengaja tidak
     * menerima keduanya), jadi $fillable tidak perlu jadi lapis proteksi
     * kedua yang malah memblokir jalur internal yang sah.
     */
    protected $fillable = [
        'artist_id',
        'category_id',
        'code_prefix',
        'product_segment',
        'name',
        'description',
        'image_path',
        'is_preorder',
        'preorder_eta',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_preorder' => 'boolean',
            'is_active' => 'boolean',
            'preorder_eta' => 'date',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Filter daftar produk — SATU definisi untuk layar Produk dan ekspor
     * (spec 041 FR-013). Dulu aturannya hanya ada di ProductController::index();
     * menyalinnya ke ekspor berarti dua definisi yang bisa menyimpang.
     *
     * Pencarian mencocokkan nama produk ATAU sku/nama varian, sehingga semua
     * varian produk yang cocok ikut — sama dengan yang tampil di layar.
     * Filter artis/kategori memakai array; nilai kosong dibuang supaya
     * whereIn([]) (selalu false) tidak diam-diam mengosongkan hasil.
     *
     * @param  array{search?: mixed, artist_id?: mixed, category_id?: mixed}  $filters
     */
    public function scopeMatchingListFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(isset($filters['search']) && $filters['search'] !== '', function (Builder $q) use ($filters) {
                $term = (string) $filters['search'];
                $q->where(function (Builder $q2) use ($term) {
                    $q2->where('name', 'like', "%{$term}%")
                        ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', "%{$term}%")
                            ->orWhere('variant_name', 'like', "%{$term}%"));
                });
            })
            ->when(count(array_filter((array) ($filters['artist_id'] ?? []))) > 0, fn (Builder $q) => $q->whereIn('artist_id', array_filter((array) $filters['artist_id'])))
            ->when(count(array_filter((array) ($filters['category_id'] ?? []))) > 0, fn (Builder $q) => $q->whereIn('category_id', array_filter((array) $filters['category_id'])));
    }
}
