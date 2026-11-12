<?php

namespace Database\Factories;

use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customer commerce — alamat tersimpan. `is_default` bawaan false: aturan
 * "alamat pertama otomatis utama" milik service, bukan factory.
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_account_id' => CustomerAccount::factory(),
            'label' => 'Rumah',
            'recipient_name' => fake()->name(),
            'phone' => '0800-'.fake()->numerify('####-####'),
            'address_line' => fake()->streetAddress(),
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => fake()->numerify('#####'),
            'notes' => null,
            'is_default' => false,
        ];
    }

    public function asDefault(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
