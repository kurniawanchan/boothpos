<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = \App\Models\PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.now()->format('Ymd').'-'.fake()->unique()->numberBetween(1000, 9999),
            'vendor_id' => Vendor::factory(),
            'status' => 'draft',
            'subtotal' => 0,
            'total_amount' => 0,
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
