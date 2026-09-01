<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI — ProductVariant menyatakan HasFactory
 * tapi tidak pernah punya factory class-nya sendiri; setiap test sebelum
 * modul vendor/BOM ini membuat varian manual lewat $product->variants()
 * ->create([...]). Modul ini butuh ProductVariant::factory() berdiri
 * sendiri (BOM/vendor-price test tidak selalu perlu detail produk induk),
 * jadi celah itu ditutup di sini alih-alih menduplikasi pola manual di
 * setiap test baru.
 */
class ProductVariantFactory extends Factory
{
    protected $model = \App\Models\ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // char(12) di skema — 3 huruf tetap + 9 karakter acak.
            'sku' => strtoupper(fake()->unique()->bothify('SKU?????????')),
            'variant_name' => 'Standard',
            'cost_price' => 0,
            'sell_price' => fake()->randomFloat(2, 5000, 100000),
            'current_stock' => 0,
            'is_active' => true,
        ];
    }
}
