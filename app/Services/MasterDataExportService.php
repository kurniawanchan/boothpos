<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\OutletItemSetting;
use App\Models\VariantOutletPrice;
use App\Models\Category;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
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
            MasterDataSheets::OUTLETS => $this->outletRows(),
            MasterDataSheets::CATEGORIES => $this->categoryRows(),
            MasterDataSheets::PRODUCTS => $this->productRows(),
            MasterDataSheets::STOCK => $this->stockRows(),
            MasterDataSheets::VENDORS => $this->vendorRows(),
            MasterDataSheets::MATERIALS => $this->materialRows(),
            MasterDataSheets::VENDOR_PRICES => $this->vendorPriceRows(),
            MasterDataSheets::BOM => $this->bomRows(),
            MasterDataSheets::MATERIAL_STOCK => $this->materialStockRows(),
            MasterDataSheets::RECIPES => $this->recipeRows(),
            MasterDataSheets::MODIFIER_GROUPS => $this->modifierGroupRows(),
            MasterDataSheets::MODIFIER_OPTIONS => $this->modifierOptionRows(),
            MasterDataSheets::MODIFIER_OPTION_MATERIALS => $this->modifierOptionMaterialRows(),
            MasterDataSheets::MODIFIER_LINKS => $this->modifierLinkRows(),
            MasterDataSheets::OUTLET_ITEMS => $this->outletItemRows(),
            MasterDataSheets::OUTLET_PRICES => $this->outletPriceRows(),
            MasterDataSheets::ROLES => $this->roleRows(),
            MasterDataSheets::USERS => $this->userRows(),
            default => throw new \InvalidArgumentException("Entitas ekspor tidak dikenali: {$entity}."),
        };
    }

    public function filename(string $entity): string
    {
        return match ($entity) {
            MasterDataSheets::OUTLETS => 'data-outlet.xlsx',
            MasterDataSheets::CATEGORIES => 'data-kategori.xlsx',
            MasterDataSheets::PRODUCTS => 'data-produk.xlsx',
            MasterDataSheets::STOCK => 'data-stok.xlsx',
            MasterDataSheets::VENDORS => 'data-vendor.xlsx',
            MasterDataSheets::MATERIALS => 'data-bahan.xlsx',
            MasterDataSheets::VENDOR_PRICES => 'data-harga-vendor.xlsx',
            MasterDataSheets::BOM => 'data-bom.xlsx',
            MasterDataSheets::MATERIAL_STOCK => 'data-stok-bahan.xlsx',
            MasterDataSheets::RECIPES => 'data-resep.xlsx',
            MasterDataSheets::MODIFIER_GROUPS => 'data-grup-modifier.xlsx',
            MasterDataSheets::MODIFIER_OPTIONS => 'data-pilihan-modifier.xlsx',
            MasterDataSheets::MODIFIER_OPTION_MATERIALS => 'data-bahan-pilihan.xlsx',
            MasterDataSheets::MODIFIER_LINKS => 'data-tautan-modifier.xlsx',
            MasterDataSheets::OUTLET_ITEMS => 'data-item-outlet.xlsx',
            MasterDataSheets::OUTLET_PRICES => 'data-harga-outlet.xlsx',
            MasterDataSheets::ROLES => 'data-peran.xlsx',
            MasterDataSheets::USERS => 'data-pengguna.xlsx',
            default => throw new \InvalidArgumentException("Entitas ekspor tidak dikenali: {$entity}."),
        };
    }

    /** 045 Stage 3 — satu baris per pasangan (outlet, item) yang tercatat; `is_carried` 1/0. */
    private function outletItemRows(): array
    {
        return \App\Support\OutletFilter::apply(OutletItemSetting::query(), 'outlet_id')->with(['outlet:id,code', 'product:id,code_prefix'])->orderBy('outlet_id')->orderBy('product_id')->get()
            ->filter(fn ($r) => $r->outlet !== null && $r->product !== null)
            ->map(fn ($r) => ['outlet_code' => $r->outlet->code, 'product_code' => $r->product->code_prefix, 'is_carried' => $r->is_carried ? 1 : 0])
            ->values()->all();
    }

    /** 045 Stage 3 — hanya harga KHUSUS outlet; varian/outlet tanpa baris mengikuti harga dasar. */
    private function outletPriceRows(): array
    {
        return \App\Support\OutletFilter::apply(VariantOutletPrice::query(), 'outlet_id')->with(['variant:id,sku', 'outlet:id,code'])->orderBy('outlet_id')->orderBy('variant_id')->get()
            ->filter(fn ($r) => $r->outlet !== null && $r->variant !== null)
            ->map(fn ($r) => ['sku' => $r->variant->sku, 'outlet_code' => $r->outlet->code, 'price' => number_format((float) $r->price, 2, '.', '')])
            ->values()->all();
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
            'kind' => $m->kind,
            'standard_cost' => $m->standard_cost,
        ])->all();
    }

    /** 090 — satu baris per (bahan, outlet) yang punya saldo; jumlah AKHIR, siap diimpor ulang. */
    private function materialStockRows(): array
    {
        // Bahan dan outlet diambil lewat model (global scope mode DEMO/LIVE dan outlet), bukan join mentah.
        $materials = Material::query()->pluck('code', 'id');
        $outlets = Outlet::query()->pluck('code', 'id');

        return \App\Models\MaterialOutletStock::query()
            ->whereIn('material_id', $materials->keys())->whereIn('outlet_id', $outlets->keys())
            ->get()
            ->map(fn ($r) => [
                'material_code' => $materials[$r->material_id], 'outlet_code' => $outlets[$r->outlet_id],
                'current_stock' => rtrim(rtrim(number_format((float) $r->current_stock, 4, '.', ''), '0'), '.'), 'reason' => null,
            ])
            ->sortBy(['outlet_code', 'material_code'])->values()->all();
    }

    /** 090 — resep aktif (bukan arsip): satu baris per bahan; kolom kepala hanya di baris pertama tiap resep. */
    private function recipeRows(): array
    {
        $rows = [];
        foreach (\App\Models\Recipe::query()->whereNull('archived_at')->with(['product', 'blocks.lines.material', 'blocks.variant'])->orderBy('product_id')->orderBy('id')->get() as $r) {
            if ($r->product === null) {
                continue;
            }
            $allVariants = $r->product->variants()->count();
            // Sheet ini memuat resep DASAR saja (blok tanpa pilihan modifier); tabel bermodifier hanya di layar Resep.
            $base = $r->blocks->filter(fn ($b) => $b->modifier_option_id === null)->values();
            $skus = $base->map(fn ($b) => $b->variant?->sku)->filter()->unique()->values();
            // 093: baris berupa PRODUK tidak punya kode bahan baku dan tidak bisa dibaca lembar ini; dilewati supaya tidak
            // tertulis baris dengan `material_code` kosong. Impor ulang tetap aman karena impor mempertahankan baris produk.
            foreach (($base->first()?->lines ?? collect())->filter(fn ($l) => $l->material_id !== null)->values() as $i => $l) {
                $rows[] = [
                    'recipe_name' => $r->name,
                    'product_code' => $r->product->code_prefix,
                    'variant_skus' => $i === 0 && $skus->count() !== $allVariants ? $skus->implode(',') : null,
                    'portions' => $i === 0 ? rtrim(rtrim(number_format((float) $r->yield, 3, '.', ''), '0'), '.') : null,
                    'effective_from' => $i === 0 ? $r->effective_from?->toDateString() : null,
                    'material_code' => $l->material?->code,
                    'quantity' => rtrim(rtrim(number_format((float) $l->quantity, 4, '.', ''), '0'), '.'),
                    'shrinkage_pct' => (float) $l->shrinkage_pct,
                    'note' => $i === 0 ? $r->note : null,
                ];
            }
        }

        return $rows;
    }

    private function modifierGroupRows(): array
    {
        return \App\Models\ModifierGroup::query()->orderBy('display_order')->orderBy('name')->get()->map(fn ($g) => [
            'name' => $g->name,
            'selection_type' => $g->selection_type,
            'is_required' => $g->is_required ? 1 : 0,
            'min_select' => $g->min_select,
            'max_select' => $g->max_select ?? 'unlimited',
            'portion_per_option' => $g->portion_per_option ? 1 : 0,
            'display_order' => $g->display_order,
            'is_active' => $g->is_active ? 1 : 0,
        ])->all();
    }

    private function modifierOptionRows(): array
    {
        $rows = [];
        foreach (\App\Models\ModifierGroup::query()->with('options')->orderBy('display_order')->orderBy('name')->get() as $g) {
            foreach ($g->options as $o) {
                $rows[] = [
                    'group_name' => $g->name, 'option_name' => $o->name, 'price_delta' => number_format((float) $o->price_delta, 2, '.', ''),
                    'min_qty' => $o->min_qty, 'max_qty' => $o->max_qty ?? 'unlimited', 'display_order' => $o->display_order, 'is_active' => $o->is_active ? 1 : 0,
                ];
            }
        }

        return $rows;
    }

    private function modifierOptionMaterialRows(): array
    {
        $rows = [];
        foreach (\App\Models\ModifierGroup::query()->with('options.materials.material')->orderBy('display_order')->orderBy('name')->get() as $g) {
            foreach ($g->options as $o) {
                foreach ($o->materials as $m) {
                    $rows[] = [
                        'group_name' => $g->name, 'option_name' => $o->name, 'material_code' => $m->material?->code,
                        'quantity' => rtrim(rtrim(number_format((float) $m->quantity, 4, '.', ''), '0'), '.'),
                    ];
                }
            }
        }

        return $rows;
    }

    private function modifierLinkRows(): array
    {
        $rows = [];
        foreach (\App\Models\ModifierGroup::query()->with(['products:id,code_prefix', 'categories:id,code'])->orderBy('display_order')->orderBy('name')->get() as $g) {
            foreach ($g->products as $p) {
                $rows[] = ['group_name' => $g->name, 'product_code' => $p->code_prefix, 'category_code' => null, 'is_linked' => 1];
            }
            foreach ($g->categories as $c) {
                $rows[] = ['group_name' => $g->name, 'product_code' => null, 'category_code' => $c->code, 'is_linked' => 1];
            }
        }

        return $rows;
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

    private function outletRows(): array
    {
        return Outlet::query()->orderBy('code')->get()->map(fn (Outlet $a) => [
            'code' => $a->code,
            'name' => $a->name,
            'contact_phone' => $a->contact_phone,
            'contact_email' => $a->contact_email,
            'notes' => $a->notes,
            'is_active' => $a->is_active ? 1 : 0,
            // 079 — di AKHIR supaya berkas ekspor lama tetap terbaca oleh impor.
            'address' => $a->address,
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
    private function productRows(): array
    {
        return ProductVariant::query()
            ->with(['product.homeOutlet', 'product.category'])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->orderBy('products.name')
            ->orderBy('product_variants.sku')
            ->select('product_variants.*')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'sku' => $v->sku,
                'home_outlet_code' => $v->product->homeOutlet->code,
                'category_code' => $v->product->category->code,
                'product_segment' => $v->product->product_segment,
                'product_name' => $v->product->name,
                'description' => $v->product->description,
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
                'image_filename' => null, // lihat catatan di categoryRows().
                'variant_image_filename' => null, // sama alasannya seperti image_filename di atas.
                'short_name' => $v->product->short_name,
                'tax_category' => $v->product->tax_category,
                'prep_minutes' => $v->product->prep_minutes,
                'is_retail' => $v->product->is_retail ? 1 : 0,
                'status' => $v->product->status,
                'stock_source' => $v->product->stock_source ?? 'variant', // 087 US6
            ])->all();
    }

    /** 045 Stage 5 — satu baris per (varian, outlet) yang punya saldo; berkas ini kembali diimpor apa adanya. */
    private function stockRows(): array
    {
        // 045 Stage 8 — ekspor hanya memuat outlet yang berlaku untuk pemanggil (bukan semua outlet).
        return \App\Support\OutletFilter::apply(\App\Models\VariantOutletStock::query(), 'outlet_id')
            ->with(['variant:id,sku', 'outlet:id,code'])
            ->get()
            ->filter(fn ($b) => $b->variant !== null && $b->outlet !== null)
            ->sortBy(fn ($b) => $b->variant->sku.'|'.$b->outlet->code)
            ->map(fn ($b) => [
                'sku' => $b->variant->sku,
                'current_stock' => $b->current_stock,
                'reason' => null,
                'outlet_code' => $b->outlet->code,
            ])->values()->all();
    }
}
