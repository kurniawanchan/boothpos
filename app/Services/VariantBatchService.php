<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 043-products-page-redesign — laci "Varian & harga": tambah / ubah / nonaktifkan varian dalam SATU transaksi
 * semua-atau-tidak-sama-sekali. Varian yang tidak disebut dibiarkan (tidak ada penghapusan lewat sini: varian
 * ber-riwayat hanya dinonaktifkan). SKU varian baru SELALU dibangkitkan server (ProductCodeGenerator), harga
 * lewat PriceService (riwayat, mode rilis, persetujuan), `cost_price` tidak disentuh.
 */
class VariantBatchService
{
    public function __construct(
        private ProductCodeGenerator $codes,
        private PriceService $prices,
        private ActivityLogger $log,
    ) {}

    /**
     * @param  list<array{id?: int|null, variant_name: string, sell_price: float|int|string, is_active?: bool}>  $variants
     * @return array{applied: list<array<string,mixed>>, pending: list<array<string,mixed>>}
     *
     * @throws ValidationException
     */
    public function apply(Product $product, array $variants, User $by, ?string $effectiveFrom = null): array
    {
        return DB::transaction(function () use ($product, $variants, $by, $effectiveFrom) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $existing = $product->variants()->lockForUpdate()->get()->keyBy('id');

            $applied = [];
            $pending = [];

            foreach ($variants as $i => $row) {
                $id = $row['id'] ?? null;

                if ($id !== null) {
                    $variant = $existing[(int) $id] ?? throw ValidationException::withMessages(["variants.{$i}.id" => __('products.variant_not_in_product')]);
                    $this->update($variant, $row, $by, $effectiveFrom, $applied, $pending);

                    continue;
                }

                $variant = $product->variants()->create([
                    'sku' => $this->codes->nextVariantSku($product),
                    'variant_name' => $row['variant_name'],
                    'cost_price' => 0,
                    'sell_price' => (float) $row['sell_price'],
                    'is_active' => $row['is_active'] ?? true,
                ]);
                $this->log->log(userId: $by->id, action: 'variant_created', entityType: 'ProductVariant', entityId: $variant->id, description: "Varian {$variant->sku} ditambahkan.", newValues: $variant->only(['sku', 'variant_name', 'sell_price']));
                $applied[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'action' => 'created'];
            }

            return ['applied' => $applied, 'pending' => $pending];
        });
    }

    private function update(ProductVariant $variant, array $row, User $by, ?string $effectiveFrom, array &$applied, array &$pending): void
    {
        $changes = [];
        if ($row['variant_name'] !== $variant->variant_name) {
            $changes['variant_name'] = $row['variant_name'];
        }
        if (array_key_exists('is_active', $row) && (bool) $row['is_active'] !== (bool) $variant->is_active) {
            $changes['is_active'] = (bool) $row['is_active'];
        }

        if ($changes !== []) {
            $before = $variant->only(array_keys($changes));
            $variant->update($changes);
            $this->log->log(userId: $by->id, action: 'variant_updated', entityType: 'ProductVariant', entityId: $variant->id, description: "Varian {$variant->sku} diubah.", oldValues: $before, newValues: $changes);
            $applied[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'action' => 'updated'];
        }

        $price = $this->prices->setBasePrice($variant, $row['sell_price'], $by, 'edit', ['effective_from' => $effectiveFrom]);
        if ($price['applied']) {
            $applied[] = ['variant_id' => $variant->id, 'sku' => $variant->sku, 'action' => 'price'];
        } elseif ($price['pending']) {
            $pending[] = [
                'variant_id' => $variant->id, 'sku' => $variant->sku, 'change_id' => $price['change']->id,
                'status' => $price['change']->status, 'new_price' => number_format((float) $price['change']->payload['new_price'], 2, '.', ''),
            ];
        }
    }
}
