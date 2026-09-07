<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = \App\Models\Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100000, 5000000);
        $discount = 0;

        return [
            'invoice_number' => 'INV-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'company_id' => Company::factory(),
            'license_id' => License::factory(),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'grand_total' => $subtotal - $discount,
            'due_date' => now()->addDays(14),
            'status' => 'unpaid',
            'payment_information' => null,
            'notes' => null,
        ];
    }
}
