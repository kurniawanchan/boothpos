<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Support\MasterDataSheets;

/**
 * Menyusun baris ekspor .xlsx per entitas master data (F15.1).
 *
 * Ada di service, bukan di controller, mengikuti aturan kodebase ini:
 * controller memvalidasi, mendelegasikan, dan membentuk response.
 *
 * Bentuk baris SENGAJA identik dengan format yang diterima
 * MasterDataImportService — berkas hasil ekspor dirancang untuk disunting
 * di Excel lalu diunggah kembali. Kolom keduanya diambil dari satu sumber
 * (MasterDataSheets) supaya tidak bisa melenceng.
 */
class MasterDataExportService
{
    public const ENTITIES = MasterDataSheets::ORDER;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $entity): array
    {
        return match ($entity) {
            MasterDataSheets::ARTISTS => $this->artistRows(),
            MasterDataSheets::CATEGORIES => $this->categoryRows(),
            MasterDataSheets::PRODUCTS => $this->productRows(),
            MasterDataSheets::STOCK => $this->stockRows(),
            default => throw new \InvalidArgumentException("Entitas ekspor tidak dikenali: {$entity}."),
        };
    }

    public function filename(string $entity): string
    {
        return match ($entity) {
            MasterDataSheets::ARTISTS => 'data-artist.xlsx',
            MasterDataSheets::CATEGORIES => 'data-kategori.xlsx',
            MasterDataSheets::PRODUCTS => 'data-produk.xlsx',
            MasterDataSheets::STOCK => 'data-stok.xlsx',
            default => throw new \InvalidArgumentException("Entitas ekspor tidak dikenali: {$entity}."),
        };
    }

    private function artistRows(): array
    {
        return Artist::query()->orderBy('code')->get()->map(fn (Artist $a) => [
            'code' => $a->code,
            'name' => $a->name,
            'contact_phone' => $a->contact_phone,
            'contact_email' => $a->contact_email,
            'notes' => $a->notes,
            'is_active' => $a->is_active ? 1 : 0,
        ])->all();
    }

    private function categoryRows(): array
    {
        return Category::query()->with('parent')->orderBy('code')->get()->map(fn (Category $c) => [
            'code' => $c->code,
            'name' => $c->name,
            // parent_code, bukan parent_id: nomor id tidak punya arti apa
            // pun di dalam spreadsheet yang disunting manusia, dan kode
            // kategori memang sudah unik.
            'parent_code' => $c->parent?->code,
            'display_order' => $c->display_order,
            'is_active' => $c->is_active ? 1 : 0,
        ])->all();
    }

    /**
     * Satu baris per VARIAN, bukan per produk — itu bentuk yang sama dengan
     * cara pemilik toko mendata SKU di spreadsheet, dan satu-satunya bentuk
     * yang bisa membawa harga per varian.
     *
     * ASUMPSI: setiap produk selalu punya minimal satu varian
     * (StoreProductRequest mewajibkan `variants` min:1, dan tidak ada
     * endpoint yang menghapus varian — hanya menonaktifkan). Produk tanpa
     * varian karena itu tidak akan muncul di ekspor ini.
     */
    private function productRows(): array
    {
        return ProductVariant::query()
            ->with(['product.artist', 'product.category'])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderBy('products.name')
            ->orderBy('product_variants.sku')
            ->select('product_variants.*')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'sku' => $v->sku,
                'artist_code' => $v->product->artist->code,
                'category_code' => $v->product->category->code,
                'product_segment' => $v->product->product_segment,
                'product_name' => $v->product->name,
                'description' => $v->product->description,
                'is_preorder' => $v->product->is_preorder ? 1 : 0,
                'preorder_eta' => $v->product->preorder_eta?->toDateString(),
                'product_is_active' => $v->product->is_active ? 1 : 0,
                'variant_name' => $v->variant_name,
                'cost_price' => number_format((float) $v->cost_price, 2, '.', ''),
                'sell_price' => number_format((float) $v->sell_price, 2, '.', ''),
                'low_stock_alert' => $v->low_stock_alert,
                'variant_is_active' => $v->is_active ? 1 : 0,
                // initial_stock sengaja dibiarkan kosong: kolom itu hanya
                // berlaku untuk varian BARU saat impor. Stok varian yang
                // sudah ada diubah lewat sheet 'stock'.
                'initial_stock' => null,
            ])->all();
    }

    private function stockRows(): array
    {
        return ProductVariant::query()
            ->orderBy('sku')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'sku' => $v->sku,
                'current_stock' => $v->current_stock,
                'reason' => null,
            ])->all();
    }
}
