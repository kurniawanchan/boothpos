<?php

namespace App\Services\CustomerCommerce;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Customer commerce (CONTRACT §6) — pembacaan katalog untuk etalase publik
 * (GET /storefront/categories, /storefront/products, /storefront/products/{slug}).
 *
 * Kelas ini TIDAK mendefinisikan kelayakan tampil: aturannya satu, di
 * Product::scopeStorefrontVisible() dan ProductVariant::scopeStorefrontOffered()
 * / scopeStorefrontSellable() (juga dipakai quote dan checkout). Di sini hanya
 * bentuk query-nya: pencarian, saringan, urutan, halaman, dan pemuatan relasi
 * tanpa N+1.
 *
 * Sengaja terpisah total dari ProductController::index() dan
 * ProductCatalogBuilder milik staf: keduanya menyaring lebih longgar (tanpa
 * syarat terbit/stok) dan membawa field khusus staf.
 *
 * Katalog mengikuti mode DEMO/LIVE yang sedang aktif lewat DataModeScope tiap
 * model; tidak ada query mentah di sini yang perlu saringan `data_mode` sendiri.
 */
class StorefrontCatalogService
{
    /** Urutan yang boleh diminta klien (nilai lain ditolak 422 oleh ListProductsRequest). */
    public const SORT_NEWEST = 'newest';

    public const SORT_NAME = 'name';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORTS = [self::SORT_NEWEST, self::SORT_NAME, self::SORT_PRICE_ASC, self::SORT_PRICE_DESC];

    public const DEFAULT_PER_PAGE = 20;

    /** Ukuran halaman terbesar; permintaan di atasnya dipangkas, bukan ditolak. */
    public const MAX_PER_PAGE = 50;

    /** Batas nomor halaman, supaya OFFSET tidak pernah melampaui bilangan bulat. */
    public const MAX_PAGE = 100000;

    /**
     * Kategori aktif yang punya minimal satu produk tampil, urut seperti di
     * layar staf (display_order, lalu nama).
     *
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $product) => $product->storefrontVisible())
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'image_path']);
    }

    /**
     * @param  array{q?: ?string, category_id?: int|string|null, sort?: ?string, page?: int|string|null, per_page?: int|string|null}  $filters  sudah divalidasi
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $categoryId = $filters['category_id'] ?? null;
        $perPage = min(max((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $page = max((int) ($filters['page'] ?? 1), 1);

        $query = $this->visibleProducts()
            // Masukan publik: %, _ dan \ diperlakukan sebagai huruf biasa, bukan wildcard LIKE.
            // Hanya NAMA produk yang dicari; SKU dan nama seller bukan bahan pencarian publik.
            ->when($term !== '', fn (Builder $q) => $q->where('products.name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($categoryId !== null, fn (Builder $q) => $q->where('products.category_id', (int) $categoryId));

        // Nama kolom urutan tidak pernah berasal dari klien: hanya empat pilihan di bawah. Setiap
        // urutan diakhiri id supaya halaman stabil saat nilainya sama.
        match ($filters['sort'] ?? self::SORT_NEWEST) {
            self::SORT_NAME => $query->orderBy('products.name')->orderBy('products.id'),
            self::SORT_PRICE_ASC => $query->orderBy('price_min')->orderBy('products.id'),
            self::SORT_PRICE_DESC => $query->orderByDesc('price_max')->orderBy('products.id'),
            default => $query->orderByDesc('products.published_at')->orderByDesc('products.id'),
        };

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Produk tampil menurut slug beserta varian yang ditawarkan; null bila
     * tidak ada ATAU tidak layak tampil (pemanggil menjawab 404 yang sama
     * untuk keduanya, supaya keberadaan produk tersembunyi tidak terbaca).
     */
    public function findBySlug(string $slug): ?Product
    {
        $product = $this->visibleProducts()->where('products.slug', $slug)->first();

        if ($product === null) {
            return null;
        }

        $variants = $product->variants()->storefrontOffered()->orderBy('id')->get();

        // Gambar varian jatuh ke gambar produk bila kosong. Relasi `product` dipasang di sini
        // (salinan tanpa relasi, supaya tidak melingkar) agar resource tidak memuatnya per varian.
        $parent = $product->withoutRelations();
        $variants->each->setRelation('product', $parent);

        return $product->setRelation('variants', $variants);
    }

    /**
     * Dasar bersama daftar dan detail: produk tampil, relasi untuk kartu, dan
     * rentang harga yang dihitung HANYA dari varian yang ditawarkan (harga
     * varian tersembunyi tidak boleh menggeser rentang). Agregat relasi, bukan
     * subquery mentah, supaya global scope varian (mode data, hapus lunak) ikut.
     */
    private function visibleProducts(): Builder
    {
        return Product::query()
            ->storefrontVisible()
            ->with(['artist:id,name', 'category:id,name'])
            ->withMin(['variants as price_min' => fn (Builder $variant) => $variant->storefrontOffered()], 'sell_price')
            ->withMax(['variants as price_max' => fn (Builder $variant) => $variant->storefrontOffered()], 'sell_price');
    }
}
