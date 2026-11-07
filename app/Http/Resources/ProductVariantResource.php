<?php

namespace App\Http\Resources;

use App\Services\BomCostCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductVariantResource extends JsonResource
{
    /** Saldo outlet pemesanan dikurangi tahanan varian (aturan tunggal di MenuStockService); null bila tak ada outlet pemesanan. */
    private function ingredientAwareStock(Request $request): ?int
    {
        return app(\App\Services\MenuStockService::class)->availableStockForRequest($request, (int) $this->id);
    }

    public function toArray(Request $request): array
    {
        // 092 — varian beresep: stok menu dari bahan (satu penghitung di server). Varian tanpa resep: `null` dan semua
        // bidang stok persis seperti sebelumnya. `outlet_stock`/`track_stock` tetap bidang yang dibaca web dan Android
        // (APK lama ikut benar); `stock_basis`/`stock_limiting` hanya tambahan.
        $recipeDriven = isset(app(\App\Services\RecipeService::class)->recipeDrivenVariantIds()[(int) $this->id]);
        $menuStock = $recipeDriven ? app(\App\Services\MenuStockService::class)->forRequest($request, (int) $this->id) : null;

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'image_path' => $this->image_path,
            // Same pattern as ProductResource — the parent product's image
            // is a separate, independent field (Product.image_path), not a
            // fallback for a variant with no image of its own.
            'image_url' => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
            'variant_name' => $this->variant_name,
            'cost_price' => number_format((float) $this->cost_price, 2, '.', ''),
            'sell_price' => number_format((float) $this->sell_price, 2, '.', ''),
            // 045 Stage 3 — harga KHUSUS di outlet tunggal yang berlaku (null = mengikuti harga dasar). Terpisah dari
            // `sell_price` supaya editor produk tidak menulis balik harga outlet sebagai harga dasar.
            'outlet_price' => ($p = app(\App\Support\OutletPriceBook::class)->forCurrentOutlet($this->id)) === null ? null : number_format($p, 2, '.', ''),
            'current_stock' => $this->current_stock,
            // 045 Stage 5 — saldo di outlet pemesanan (hanya untuk `pos=1`); `current_stock` tetap total.
            // 093 — varian produk bahan: saldo dikurangi bahan yang DITAHAN tab terbuka (resep menu lain), supaya kasir tidak
            // menjual langsung yang sudah dijanjikan. Varian di luar resep: persis seperti sebelumnya (held = 0).
            'outlet_stock' => $recipeDriven ? ($menuStock['portions'] ?? null) : $this->ingredientAwareStock($request),
            'low_stock_alert' => $this->low_stock_alert,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            // 034-seller-po-bom — selama true, cost_price mengikuti biaya BOM
            // dan dikunci terhadap edit manual.
            'bom_complete' => (bool) $this->bom_complete,
            // has_bom/bom_cost hanya ada bila baris BOM sengaja dimuat
            // pemanggil (ProductController::variantRelations) — daftar
            // produk/POS tidak menarik BOM varian satu per satu.
            $this->mergeWhen($this->relationLoaded('bomLines'), fn () => [
                'has_bom' => $this->bomLines->isNotEmpty(),
                'bom_cost' => $this->bomLines->isNotEmpty()
                    ? app(BomCostCalculator::class)->breakdown($this->resource)['bom_cost']
                    : null,
            ]),
            // 028 — false untuk hidangan made-to-order (tanpa stok fisik).
            // 087 US6 (research D19) — payload POS (`pos=1`) mengirim nilai EFEKTIF (produk bersumber modifier = false),
            // sehingga web dan Android lama menampilkannya tanpa stok dan tidak pernah "habis" tanpa perubahan klien.
            // Payload pengelolaan tetap mentah (formulir menyimpannya balik), ditambah `stock_tracked` efektif.
            // 092 — varian beresep di payload POS punya angka (`track_stock` true) hanya bila ada bahan pembatas aktif.
            'track_stock' => $request->boolean('pos')
                ? ($recipeDriven ? ($menuStock['portions'] ?? null) !== null : $this->resource->tracksOwnStock())
                : (bool) $this->track_stock,
            'stock_tracked' => $this->resource->tracksOwnStock(),
            'stock_basis' => $recipeDriven ? 'recipe' : 'variant',
            'stock_limiting' => $menuStock['limiting'] ?? null,
        ];
    }
}
