<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use App\Models\Concerns\HasSellerScope;
use App\Support\ModeGate;
use App\Support\ProductSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasDataMode, HasSellerScope, HasFactory, SoftDeletes;

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
            'published_at' => 'datetime',
        ];
    }

    /**
     * Customer commerce — `slug` (pengenal publik di etalase) dibuat DI SINI,
     * bukan di tiap pemanggil, supaya semua jalur pembuatan produk
     * (ProductController::store, impor Excel master data, seeder demo,
     * factory) mendapatkannya tanpa perlu tahu. Klon LIVE → DEMO memakai
     * insert mentah dan menyalin slug apa adanya (unik per mode, jadi aman).
     *
     * `slug` dan `published_at` SENGAJA tidak ada di $fillable: slug tidak
     * boleh berubah saat produk diganti nama (tautan dan cache aplikasi
     * Android harus tetap sah) dan tidak boleh datang dari input klien;
     * penerbitan hanya lewat aksi staf tersendiri. UpdateProductRequest juga
     * tidak punya rule untuk keduanya.
     *
     * Keunikan dicek di dalam MODE DATA baris itu, termasuk baris yang sudah
     * dihapus lunak (indeks unik `data_mode, slug` tidak mengenal
     * `deleted_at`) dan tanpa scope seller (seller lain ikut memakai slug).
     * Dua pembuatan bersamaan dengan nama sama tetap dijaga indeks unik.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (filled($product->slug)) {
                return;
            }

            $mode = $product->data_mode ?? ModeGate::current();
            $base = ProductSlug::base($product->name);

            // $base hanya berisi [a-z0-9-], jadi aman dipakai di LIKE tanpa escape.
            $taken = static::withoutGlobalScopes()
                ->where('data_mode', $mode)
                ->where(fn (Builder $q) => $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
                ->pluck('slug')
                ->all();

            $product->slug = ProductSlug::dedupe($base, array_fill_keys($taken, true));
        });
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
     * Customer commerce (CONTRACT §6) — syarat kelayakan tampil yang melekat
     * pada BARIS PRODUK: aktif, sudah terbit (`published_at` terisi dan tidak
     * di masa depan), bukan pre-order (alur pemenuhannya berbeda; ASUMSI
     * kontrak), seller aktif, kategori aktif. Baris terhapus lunak, baris mode
     * data lain dan seller/kategori terhapus tersaring oleh global scope
     * masing-masing model (whereHas ikut menerapkannya).
     *
     * Dipisah dari scopeStorefrontVisible() supaya
     * ProductVariant::scopeStorefrontSellable() bisa memakai syarat yang SAMA
     * tanpa lingkaran (produk tampil ⇄ varian layak jual).
     */
    public function scopeStorefrontListable(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn('is_preorder'), false)
            // NULL (belum terbit) otomatis gagal di perbandingan ini.
            ->where($query->qualifyColumn('published_at'), '<=', now())
            ->whereHas('artist', fn (Builder $artist) => $artist->where('is_active', true))
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }

    /**
     * Customer commerce (CONTRACT §6) — SATU-satunya definisi "produk tampil
     * di etalase": layak tampil sebagai baris (scopeStorefrontListable) DAN
     * punya minimal satu varian yang ditawarkan
     * (ProductVariant::scopeStorefrontOffered). Dipakai daftar, detail dan
     * kategori etalase; jangan menulis ulang syaratnya di tempat lain.
     */
    public function scopeStorefrontVisible(Builder $query): Builder
    {
        return $query
            ->storefrontListable()
            ->whereHas('variants', fn (Builder $variant) => $variant->storefrontOffered());
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
