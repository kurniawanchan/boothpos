<?php

namespace Database\Factories;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customer commerce — baris pesanan pelanggan untuk test. Snapshot diisi
 * nilai sintetis; test yang peduli pada isi snapshot membuat pesanannya
 * lewat service checkout.
 */
class CustomerOrderItemFactory extends Factory
{
    protected $model = CustomerOrderItem::class;

    public function definition(): array
    {
        return [
            'customer_order_id' => CustomerOrder::factory(),
            'variant_id' => ProductVariant::factory(),
            'sku_snapshot' => strtoupper(fake()->bothify('SKU-???-###')),
            'name_snapshot' => 'Produk Uji — Standard',
            'unit_price' => '25000.00',
            'qty' => 2,
            'line_total' => '50000.00',
        ];
    }
}
