<?php

namespace Database\Factories;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderShipment;
use App\Support\Couriers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customer commerce — pengiriman kurir untuk test (pesanan induknya otomatis
 * berjenis kurir). Nomor resi sintetis.
 */
class CustomerOrderShipmentFactory extends Factory
{
    protected $model = CustomerOrderShipment::class;

    public function definition(): array
    {
        return [
            'customer_order_id' => CustomerOrder::factory()->courier(),
            'courier_name' => Couriers::DEFAULT,
            'service_name' => 'REG',
            'tracking_number' => strtoupper(fake()->bothify('RESI-########')),
            'shipping_cost' => '0.00',
            'dispatched_at' => now(),
            'delivered_at' => null,
        ];
    }
}
