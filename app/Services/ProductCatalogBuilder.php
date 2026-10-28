<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\BuildsInvoiceDocument;
use App\Support\Code128;

/**
 * 049-product-variant-catalog-export — SATU sumber data katalog: dipakai JSON `GET /catalog` (PDF
 * digambar SPA dari sini) dan ekspor Excel, supaya kedua format tidak bisa berbeda (pola yang sama
 * dengan SalesTransactionsService / Product::scopeMatchingListFilters).
 *
 * Aturan isi:
 *  - hanya produk AKTIF dan varian AKTIF yang tidak dihapus; DEMO/LIVE mengikuti scope model;
 *  - filter (search, artist_id[], category_id[]) memakai scope yang SAMA dengan daftar produk, jadi
 *    katalog berisi persis yang tampil di layar Produk;
 *  - HARGA MODAL, stok, BOM, dan status pre-order tidak pernah dibaca ke keluaran — katalog dibagikan
 *    ke kasir dan pembeli (FR-007, SC-005);
 *  - identitas toko memakai perakitan yang sama dengan invoice/struk (BuildsInvoiceDocument), bukan
 *    salinan baru; baris yang belum diisi bernilai null sehingga hilang dari dokumen.
 */
class ProductCatalogBuilder
{
    use BuildsInvoiceDocument;

    public function __construct(private readonly ImageUploadService $images) {}

    /**
     * @param  array{search?: mixed, artist_id?: mixed, category_id?: mixed}  $filters
     * @param  bool  $withImagePaths  hanya untuk Excel: menambah `image_path` (path disk publik, varian lalu produk)
     *                                supaya gambar bisa dibaca dari disk; API JSON tidak pernah membocorkan path.
     * @return array{generated_at: string, store: array<string, mixed>, totals: array{sellers: int, variants: int}, sellers: list<array<string, mixed>>}
     *
     * @throws CatalogTooLargeException
     */
    public function build(array $filters, bool $withImagePaths = false): array
    {
        $max = (int) config('catalog.max_variants', 2000);
        if ($this->variantCount($filters) > $max) {
            throw new CatalogTooLargeException($max);
        }

        $products = Product::query()
            ->where('is_active', true)
            ->matchingListFilters($filters)
            // Penjual/kategori yang sudah dihapus tetap tampil namanya (sama dengan layar Stok).
            ->with([
                'artist' => fn ($q) => $q->withTrashed(),
                'category' => fn ($q) => $q->withTrashed(),
                'variants' => fn ($q) => $q->where('is_active', true),
            ])
            ->get();

        $sellers = [];
        $variantTotal = 0;
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                // image_url membaca $variant->product; tanpa ini setiap varian memicu satu query (N+1).
                $variant->setRelation('product', $product);

                $sellers[$product->artist_id]['artist_id'] = $product->artist_id;
                $sellers[$product->artist_id]['name'] = $product->artist?->name ?? '—';
                $sellers[$product->artist_id]['variants'][] = $this->variantRow($product, $variant, $withImagePaths);
                $variantTotal++;
            }
        }

        $sellers = collect($sellers)
            ->map(function (array $seller) {
                // Urut kategori -> produk -> varian TANPA peduli huruf besar/kecil ("alpha" tidak jatuh setelah "Zeta").
                $key = fn (array $v) => [mb_strtolower((string) $v['category_name']), mb_strtolower($v['product_name']), mb_strtolower($v['variant_name'])];
                usort($seller['variants'], fn (array $a, array $b) => $key($a) <=> $key($b) ?: strnatcasecmp($a['code'], $b['code']));

                return $seller;
            })
            ->sort(fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']))
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'store' => $this->buildStoreIdentity($this->images),
            'totals' => ['sellers' => count($sellers), 'variants' => $variantTotal],
            'sellers' => $sellers,
        ];
    }

    /** Hitung dulu sebelum memuat apa pun, supaya katalog raksasa ditolak tanpa menghabiskan memori. */
    private function variantCount(array $filters): int
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true)->matchingListFilters($filters))
            ->count();
    }

    /** @return array<string, mixed> */
    private function variantRow(Product $product, ProductVariant $variant, bool $withImagePaths): array
    {
        $barcode = Code128::pattern($variant->sku);

        $row = [
            'variant_id' => $variant->id,
            'category_name' => $product->category?->name,
            'product_name' => $product->name,
            'variant_name' => (string) $variant->variant_name,
            'code' => $variant->sku,
            'sell_price' => number_format((float) $variant->sell_price, 2, '.', ''),
            'image_url' => $variant->image_url,
            // null = kode tak bisa dibawa Code 128; baris tetap tampil dengan kode terbacanya.
            'barcode' => $barcode === null ? null : ['type' => 'code128', 'pattern' => $barcode, 'text' => $variant->sku],
        ];

        if ($withImagePaths) {
            // Aturan fallback yang sama dengan ProductVariant::image_url (gambar varian, lalu gambar produk).
            $row['image_path'] = $variant->image_path ?: $product->image_path;
        }

        return $row;
    }
}
