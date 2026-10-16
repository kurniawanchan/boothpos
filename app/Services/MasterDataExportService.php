<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Category;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use App\Support\MasterDataSheets;
use App\Support\ReportSplit;

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
     * @param  array{search?: mixed, artist_id?: mixed, category_id?: mixed}  $filters
     *         Hanya dipakai sheet 'products' — filter layar Produk (spec 041,
     *         FR-013). Kosong = semua varian, seperti sebelumnya.
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $entity, array $filters = []): array
    {
        return match ($entity) {
            MasterDataSheets::ARTISTS => $this->artistRows(),
            MasterDataSheets::CATEGORIES => $this->categoryRows(),
            MasterDataSheets::PRODUCTS => $this->productRows($filters),
            MasterDataSheets::STOCK => $this->stockRows(),
            MasterDataSheets::VENDORS => $this->vendorRows(),
            MasterDataSheets::MATERIALS => $this->materialRows(),
            MasterDataSheets::VENDOR_PRICES => $this->vendorPriceRows(),
            MasterDataSheets::BOM => $this->bomRows(),
            MasterDataSheets::ROLES => $this->roleRows(),
            MasterDataSheets::USERS => $this->userRows(),
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
            MasterDataSheets::VENDORS => 'data-vendor.xlsx',
            MasterDataSheets::MATERIALS => 'data-bahan.xlsx',
            MasterDataSheets::VENDOR_PRICES => 'data-harga-vendor.xlsx',
            MasterDataSheets::BOM => 'data-bom.xlsx',
            MasterDataSheets::ROLES => 'data-peran.xlsx',
            MasterDataSheets::USERS => 'data-pengguna.xlsx',
            default => throw new \InvalidArgumentException("Entitas ekspor tidak dikenali: {$entity}."),
        };
    }

    /**
     * User Story 4 (T052) — menu_keys digabung jadi satu sel dipisah koma,
     * pola yang sama seperti kolom impor sheet ini (MasterDataSheets).
     */
    private function roleRows(): array
    {
        return Role::query()->orderBy('name')->get()->map(fn (Role $r) => [
            'name' => $r->name,
            'menu_keys' => implode(',', $r->menu_keys ?? []),
        ])->all();
    }

    /**
     * FR-007 — TIDAK PERNAH memuat kolom password, baik nilainya (hash)
     * maupun kolomnya sendiri. photo_path diekspor sebagai STRING referensi
     * path penyimpanan, bukan data biner gambarnya — konsisten dengan
     * bagaimana image_filename ditangani sheet lain (kosong = tidak
     * diikutkan/tidak diubah), dan supaya berkas .xlsx-nya tetap ringan.
     */
    private function userRows(): array
    {
        return User::query()->with('role')->orderBy('username')->get()->map(fn (User $u) => [
            'name' => $u->name,
            'username' => $u->username,
            'role_name' => $u->role?->name,
            'is_active' => $u->is_active ? 1 : 0,
            'photo_path' => $u->photo_path,
        ])->all();
    }

    private function vendorRows(): array
    {
        return Vendor::query()->orderBy('code')->get()->map(fn (Vendor $v) => [
            'code' => $v->code,
            'name' => $v->name,
            'contact_phone' => $v->contact_phone,
            'contact_email' => $v->contact_email,
            'notes' => $v->notes,
            'is_active' => $v->is_active ? 1 : 0,
        ])->all();
    }

    private function materialRows(): array
    {
        return Material::query()->orderBy('code')->get()->map(fn (Material $m) => [
            'code' => $m->code,
            'name' => $m->name,
            'unit' => $m->unit,
            'notes' => $m->notes,
            'is_active' => $m->is_active ? 1 : 0,
        ])->all();
    }

    private function vendorPriceRows(): array
    {
        return VendorMaterialPrice::query()
            ->with(['vendor', 'material'])
            ->join('vendors', 'vendors.id', '=', 'vendor_material_prices.vendor_id')
            ->join('materials', 'materials.id', '=', 'vendor_material_prices.material_id')
            ->orderBy('materials.code')
            ->orderBy('vendors.code')
            ->select('vendor_material_prices.*')
            ->get()
            ->map(fn (VendorMaterialPrice $p) => [
                'vendor_code' => $p->vendor->code,
                'material_code' => $p->material->code,
                'price' => number_format((float) $p->price, 2, '.', ''),
                'is_preferred' => $p->is_preferred ? 1 : 0,
                'notes' => $p->notes,
            ])->all();
    }

    /**
     * Satu baris per baris BOM (varian + bahan), lintas seluruh varian —
     * bentuk yang sama dengan yang diterima import (sku + material_code).
     */
    private function bomRows(): array
    {
        return \App\Models\ProductVariantBomLine::query()
            ->with(['variant', 'material'])
            ->join('product_variants', 'product_variants.id', '=', 'product_variant_bom_lines.product_variant_id')
            ->join('materials', 'materials.id', '=', 'product_variant_bom_lines.material_id')
            ->orderBy('product_variants.sku')
            ->orderBy('materials.code')
            ->select('product_variant_bom_lines.*')
            ->get()
            ->map(fn ($line) => [
                'sku' => $line->variant->sku,
                'material_code' => $line->material->code,
                'qty_needed' => number_format((float) $line->qty_needed, 4, '.', ''),
                'notes' => $line->notes,
            ])->all();
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
            // Kosong dengan sengaja (Task 6) — kita tidak menyimpan nama
            // berkas ASLI yang diunggah pengguna, hanya path acak di
            // storage, jadi tidak ada nama untuk direkonstruksi di sini.
            // Kolom kosong berarti "jangan diubah" saat diimpor ulang,
            // konsisten dengan gambar yang sudah ada tetap dipertahankan.
            'image_filename' => null,
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
    /**
     * @param  array{search?: mixed, artist_id?: mixed, category_id?: mixed}  $filters
     */
    private function productRows(array $filters): array
    {
        return ProductVariant::query()
            // Produk yang lolos filter layar, dengan SEMUA variannya (sama
            // seperti layar yang menampilkan produk, bukan varian satu-satu).
            ->whereHas('product', fn ($q) => $q->matchingListFilters($filters))
            ->with(['product.artist', 'product.category'])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderBy('products.name')
            ->orderBy('product_variants.sku')
            ->select('product_variants.*')
            ->get()
            ->map(function (ProductVariant $v) {
                // Urutan KUNCI di bawah = urutan kolom di ekspor (writer
                // Excel memakai urutan array, bukan judul). Harus sama
                // dengan MasterDataSheets::PRODUCTS_EXPORT.
                $code = $this->skuTail($v->sku);
                $categoryName = $v->product->category->name;

                return [
                    'sku' => $v->sku,
                    'code' => $code,
                    'artist_code' => $v->product->artist->code,
                    'artist_name' => $v->product->artist->name,
                    'category_code' => $v->product->category->code,
                    'category_name' => $categoryName,
                    'product_name' => $v->product->name,
                    'variant_name' => $v->variant_name,
                    'item_label' => "{$code} - {$categoryName} {$v->variant_name}",
                    'description' => $v->product->description,
                    // Stok fisik varian saat ini (spec 041, FR-012). Stok per
                    // varian, bukan total produk: satu baris = satu varian.
                    'total_stock_available' => (int) $v->current_stock,
                    'cost_price' => number_format((float) $v->cost_price, 2, '.', ''),
                    'sell_price' => number_format((float) $v->sell_price, 2, '.', ''),
                    // Selisih dalam sen memakai ReportSplit — rumus uang yang
                    // sama dengan laporan lain, bukan aritmetika float baru.
                    'profit_margin' => ReportSplit::money(
                        ReportSplit::cents($v->sell_price) - ReportSplit::cents($v->cost_price),
                    ),
                    'low_stock_alert' => $v->low_stock_alert,
                ];
            })->all();
    }

    /**
     * 7 karakter terakhir SKU, atau SKU utuh bila lebih pendek. Dipakai
     * sebagai `code` dan awalan `item_label` (spec 041, FR-005). substr()
     * dengan start negatif melebihi panjang string mengembalikan seluruh
     * string di PHP 8 — dikunci oleh test_products_export_short_sku_*.
     */
    private function skuTail(string $sku): string
    {
        return substr($sku, -7);
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
