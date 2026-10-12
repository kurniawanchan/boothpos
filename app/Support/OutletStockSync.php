<?php

namespace App\Support;

use App\Models\Material;
use App\Models\MaterialOutletStock;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantOutletStock;
use Illuminate\Support\Facades\DB;

/**
 * 045 Stage 5 — menjaga invarian I2 (total tersimpan = Σ saldo outlet) untuk penulisan LANGSUNG ke `current_stock`
 * di luar StockService: pembuatan varian/bahan dengan stok awal, factory/seeder, dan koreksi data. Penulisan seperti
 * itu tidak punya baris riwayat (tidak pernah ada, sebelum maupun sesudah 045), jadi selisihnya dibukukan ke saldo
 * outlet BAWAAN (outlet yang akan dipakai baris baru pada konteks itu — sama dengan bawaan shift/meja, jadi data
 * "apa adanya" konsisten); selisih negatif mengambil dari outlet lain bila saldo bawaan tidak cukup. Migrasi
 * sebaliknya memindahkan saldo lama ke outlet ASAL produk (spec), bukan lewat kelas ini.
 *
 * StockService/MaterialStockService membungkus tulisan mereka sendiri dalam `internally()` supaya hook model
 * tidak menggandakan perubahan yang sudah mereka bukukan.
 */
final class OutletStockSync
{
    private static int $depth = 0;

    public static function internally(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth === 0;
    }

    public static function homeOutletOf(ProductVariant $variant): ?int
    {
        $id = Product::withTrashed()->whereKey($variant->product_id)->value('home_outlet_id');

        return $id !== null ? (int) $id : self::firstOutlet();
    }

    public static function firstOutlet(): ?int
    {
        $id = Outlet::withTrashed()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Outlet bawaan untuk penulisan langsung: outlet yang dipakai baris baru pada konteks ini, selain itu yang pertama. */
    public static function defaultOutlet(): ?int
    {
        return OutletDefaults::peekForNewRecord() ?? self::firstOutlet();
    }

    /** Varian baru selalu mendapat baris saldo di outlet bawaan (walau stok awal 0), supaya "stok menipis" melihatnya. */
    public static function variantCreated(ProductVariant $variant): void
    {
        if (! self::active()) {
            return;
        }
        $home = self::defaultOutlet();
        if ($home === null) {
            return;
        }
        VariantOutletStock::query()->firstOrCreate(['variant_id' => $variant->id, 'outlet_id' => $home], ['current_stock' => 0]);
        self::variantChanged($variant, (int) $variant->current_stock);
    }

    public static function variantChanged(ProductVariant $variant, int $delta): void
    {
        if ($delta === 0 || ! self::active()) {
            return;
        }
        $home = self::defaultOutlet();
        if ($home === null) {
            return;
        }

        DB::transaction(function () use ($variant, $delta, $home) {
            $rows = VariantOutletStock::query()->where('variant_id', $variant->id)->lockForUpdate()->get()->keyBy('outlet_id');
            $row = $rows->get($home) ?? VariantOutletStock::create(['variant_id' => $variant->id, 'outlet_id' => $home, 'current_stock' => 0]);
            $rows->put($home, $row);

            if ($delta > 0) {
                $row->update(['current_stock' => $row->current_stock + $delta]);

                return;
            }
            $need = -$delta;
            foreach ($rows->sortBy(fn ($r) => (int) $r->outlet_id !== $home)->values() as $r) {
                $take = min($need, (int) $r->current_stock);
                if ($take > 0) {
                    $r->update(['current_stock' => $r->current_stock - $take]);
                    $need -= $take;
                }
                if ($need === 0) {
                    break;
                }
            }
        });
    }

    public static function materialChanged(Material $material, float $delta): void
    {
        if (abs($delta) < 0.0005 || ! self::active()) {
            return;
        }
        $outlet = self::defaultOutlet();
        if ($outlet === null) {
            return;
        }

        DB::transaction(function () use ($material, $delta, $outlet) {
            $rows = MaterialOutletStock::query()->where('material_id', $material->id)->lockForUpdate()->get()->keyBy('outlet_id');
            $row = $rows->get($outlet) ?? MaterialOutletStock::create(['material_id' => $material->id, 'outlet_id' => $outlet, 'current_stock' => 0]);
            $rows->put($outlet, $row);

            if ($delta > 0) {
                $row->update(['current_stock' => (float) $row->current_stock + $delta]);

                return;
            }
            $need = -$delta;
            foreach ($rows->sortBy(fn ($r) => (int) $r->outlet_id !== $outlet)->values() as $r) {
                $take = min($need, (float) $r->current_stock);
                if ($take > 0) {
                    $r->update(['current_stock' => (float) $r->current_stock - $take]);
                    $need -= $take;
                }
                if ($need < 0.0005) {
                    break;
                }
            }
        });
    }
}
