<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Services\ProductCodeGenerator;

class ProductFactory extends Factory
{
    protected $model = \App\Models\Product::class;

    public function definition(): array
    {
        // artist_id dan category_id WAJIB diberikan eksplisit oleh
        // pemanggil (lihat pola di seluruh test), karena code_prefix
        // bergantung pada kode keduanya dan tidak bisa ditebak di sini.
        return [
            'artist_id' => \App\Models\Artist::factory(),
            'category_id' => \App\Models\Category::factory(),
            'code_prefix' => strtoupper(fake()->unique()->lexify('????????')),
            'product_segment' => strtoupper(fake()->lexify('???')),
            'name' => fake()->words(2, true),
            'is_preorder' => false,
            'is_active' => true,
        ];
    }
}
