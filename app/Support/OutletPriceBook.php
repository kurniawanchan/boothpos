<?php

namespace App\Support;

use App\Models\VariantOutletPrice;

/**
 * 045 Stage 3 — buku harga khusus outlet untuk SATU permintaan: memuat semua harga khusus sebuah outlet dengan satu
 * query lalu menjawab per varian tanpa query lagi (daftar produk/POS tidak boleh N+1). Terdaftar `scoped`; setiap
 * penulisan harga lewat PriceService memanggil `flush()` agar tidak basi dalam proses yang sama.
 */
final class OutletPriceBook
{
    /** @var array<int, array<int, float>> outletId => [variantId => harga] */
    private array $loaded = [];

    public function priceOrNull(int $variantId, int $outletId): ?float
    {
        $this->loaded[$outletId] ??= VariantOutletPrice::query()->where('outlet_id', $outletId)->pluck('price', 'variant_id')
            ->map(fn ($p) => round((float) $p, 2))->all();

        return $this->loaded[$outletId][$variantId] ?? null;
    }

    /** @var array<int, array<int, int>> outletId => [variantId => saldo] */
    private array $stock = [];

    /**
     * 045 Stage 5 — saldo varian di outlet PEMESANAN yang dipaksakan (`pos=1`/`stock_outlet`), atau null bila tak ada.
     * Satu query per permintaan (semua saldo outlet itu), jadi daftar tidak N+1.
     */
    public function orderingStockOrNull(int $variantId): ?int
    {
        if ($this->ordering === null) {
            return null;
        }
        $this->stock[$this->ordering] ??= \App\Models\VariantOutletStock::query()->where('outlet_id', $this->ordering)->pluck('current_stock', 'variant_id')
            ->map(fn ($n) => (int) $n)->all();

        return $this->stock[$this->ordering][$variantId] ?? 0;
    }

    public function flush(): void
    {
        $this->loaded = [];
        $this->stock = [];
    }

    /** Outlet pemesanan yang dipaksakan untuk permintaan ini (layar POS/tab, `pos=1`); null = pakai outlet tunggal yang berlaku. */
    private ?int $ordering = null;

    public function useOrderingOutlet(?int $outletId): void
    {
        $this->ordering = $outletId;
    }

    /** Harga khusus untuk outlet tunggal yang berlaku pada permintaan ini, atau null (tanpa outlet tunggal / mengikuti dasar). */
    public function forCurrentOutlet(int $variantId): ?float
    {
        $outletId = $this->ordering ?? app(OutletContext::class)->singleId();

        return $outletId === null ? null : $this->priceOrNull($variantId, $outletId);
    }
}
