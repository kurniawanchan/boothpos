<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialStockMovement;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorMaterialPrice;
use App\Services\MaterialStockService;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 088 — dunia uji laporan bahan, di atas dunia 087 (ModifierMaterialFixtures: outlet A/B, Indomie Kuah + Nasi Goreng,
 * bahan soto/ayam/telur/cabai, Telur bervendor). Tambahan:
 *  - vendor pilihan untuk Indomie Soto; Ayam Bawang & Cabai TANPA harga vendor;
 *  - bahan setengah jadi "Sambal" ber-biaya standar (biaya resep cadangan, research D5);
 *  - grup Topping juga tertaut ke KATEGORI "Mie" yang berisi "Mie Goreng" (warisan) dan "Mie Rebus" yang MELEPAS
 *    grup itu (086) — menguji pembaca balik pewarisan grup;
 *  - pembantu hari jual dan pergerakan bertanggal (laju per hari jual, research D2).
 */
trait MaterialReportFixtures
{
    use ModifierMaterialFixtures;

    protected Vendor $grosir;

    protected Material $sambal;

    protected Product $mieGoreng;

    protected Product $mieRebus;

    protected function buildMaterialReportWorld(): void
    {
        $this->buildModifierMaterialWorld();

        $this->grosir = Vendor::factory()->create(['name' => 'Tukang Grosir Indomie']);
        VendorMaterialPrice::create(['vendor_id' => $this->grosir->id, 'material_id' => $this->mat['soto']->id, 'price' => 3000, 'is_preferred' => true]);
        $this->sambal = Material::factory()->create(['name' => 'Sambal', 'unit' => 'gram', 'kind' => 'semi_finished', 'standard_cost' => 50, 'current_stock' => 0]);

        $mie = Category::factory()->create(['name' => 'Mie']);
        $this->mieGoreng = Product::factory()->create(['name' => 'Mie Goreng', 'category_id' => $mie->id, 'home_outlet_id' => $this->outletA->id]);
        $this->mieRebus = Product::factory()->create(['name' => 'Mie Rebus', 'category_id' => $mie->id, 'home_outlet_id' => $this->outletA->id]);
        $topping = $this->opt['telur']->group;
        $topping->categories()->attach($mie->id);
        $this->mieRebus->excludedModifierGroups()->attach($topping->id);
    }

    protected function topping(): ModifierGroup
    {
        return $this->opt['telur']->group;
    }

    /** Tanggal bisnis `n` hari sebelum hari ini. */
    protected function daysAgo(int $n): string
    {
        return CarbonImmutable::parse(BusinessDay::today(), BusinessDay::timezone())->subDays($n)->toDateString();
    }

    /** Waktu di tengah hari bisnis `date` (aman dari jam tutup buku), untuk memundurkan pergerakan. */
    protected function midday(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 12:00:00', BusinessDay::timezone());
    }

    /**
     * Membuat satu pesanan `completed` per tanggal di outlet itu (hari jual, research D2) TANPA memotong bahan:
     * baris pesanan memakai produk tanpa modifier, lalu tanggal bisnisnya dimundurkan.
     */
    protected function sellDays(Outlet $outlet, array $dates): void
    {
        foreach ($dates as $date) {
            $order = Order::withoutGlobalScopes()->whereKey($this->checkoutPlainAt($outlet))->firstOrFail();
            // Nomor pesanan dihitung per hari (TRX-tanggal-urut): memundurkan pesanan menurunkan hitungan hari ini dan
            // checkout berikutnya akan memakai nomor yang sama. Nomor uji yang unik mencegah tabrakan itu.
            DB::table('orders')->where('id', $order->id)->update(['business_date' => $date, 'created_at' => $this->midday($date), 'order_number' => 'TST-'.$order->id]);
        }
    }

    /** Pesanan selesai tanpa bahan di outlet itu; mengembalikan id. */
    private function checkoutPlainAt(Outlet $outlet): int
    {
        // Selalu checkout di outlet shift (A, punya stok varian), lalu pindahkan pesanan ke outlet tujuan: yang diuji
        // hanya keberadaan pesanan `completed` per outlet per tanggal bisnis, bukan penjualan di outlet itu.
        $id = $this->checkout([[$this->nasiGoreng, 1, []]])->assertCreated()->json('id');
        DB::table('orders')->where('id', $id)->update(['outlet_id' => $outlet->id]);

        return $id;
    }

    /** Pergerakan bahan bertanggal lewat jalur tulis yang sah (MaterialStockService), lalu waktunya dimundurkan. */
    protected function move(string $key, Outlet $outlet, string $type, float $qty, ?string $date = null, ?string $reason = null): MaterialStockMovement
    {
        $material = $key === 'sambal' ? $this->sambal : $this->mat[$key];
        $m = app(MaterialStockService::class)->applyMovement($material, $outlet->id, $type, $qty, $type === 'sale' || $type === 'return' || $type === 'waste' ? 'order_item' : null, null, null, $reason);
        if ($date !== null) {
            DB::table('material_stock_movements')->where('id', $m->id)->update(['created_at' => $this->midday($date)]);
        }

        return $m;
    }

    /**
     * Skenario risiko bersama (MaterialReportCoreTest + endpoint): A punya 4 hari jual dalam 7 hari terakhir; Indomie
     * Soto di A: jual 18, kembali 4, buang 2 → laju 4/hari, saldo 6 → 1,5 hari ("akan habis"). B: Ayam Bawang minus,
     * Telur habis. Sambal hanya di resep (pemakaian tidak tercatat). Cabai di A tidak terpakai.
     */
    protected function seedRiskScenario(): void
    {
        $this->sellDays($this->outletA, [$this->daysAgo(1), $this->daysAgo(2), $this->daysAgo(4), $this->daysAgo(6)]);
        foreach ([1, 2, 4, 6] as $d) {
            $this->move('soto', $this->outletA, 'sale', -4.5, $this->daysAgo($d));
        }
        $this->move('soto', $this->outletA, 'return', 4, $this->daysAgo(2));
        $this->move('soto', $this->outletA, 'waste', -2, $this->daysAgo(4));
        // Pergerakan di luar jendela 7 hari tidak dihitung.
        $this->move('soto', $this->outletA, 'sale', -50, $this->daysAgo(20));
        $this->setStock('soto', $this->outletA, 6);

        // B: Ayam Bawang minus, Telur habis (tanpa pemakaian), Cabai di A tidak terpakai.
        $this->setStock('ayam', $this->outletB, 0);
        $this->move('ayam', $this->outletB, 'sale', -2, $this->daysAgo(1));
        $this->sellDays($this->outletB, [$this->daysAgo(1)]);
        $this->setStock('telur', $this->outletB, 0);

        // Sambal: hanya dipakai resep (tidak memotong stok) → "pemakaian tidak tercatat".
        $recipe = \App\Models\Recipe::create(['product_id' => $this->nasiGoreng->product_id, 'version' => 1, 'yield' => 1, 'effective_from' => $this->daysAgo(10)]);
        $recipe->lines()->create(['material_id' => $this->sambal->id, 'quantity' => 10, 'shrinkage_pct' => 0, 'display_order' => 0]);
        $this->move('sambal', $this->outletA, 'adjustment', 500);

    }
}
