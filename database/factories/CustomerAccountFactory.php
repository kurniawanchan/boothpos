<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Customer commerce — akun pelanggan untuk test. Kata sandi bawaan adalah
 * nilai uji `password` (sama dengan UserFactory), di-hash sekali per proses.
 * Baris `customers`-nya dibuat di mode data yang sama (factory berjalan di
 * mode aktif), tanpa seller — seperti hasil registrasi sungguhan.
 */
class CustomerAccountFactory extends Factory
{
    protected $model = CustomerAccount::class;

    private static ?string $password = null;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory()->state(['artist_id' => null, 'notes' => null]),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
            'disabled_at' => null,
            'last_login_at' => null,
        ];
    }

    /** Akun yang dinonaktifkan: tidak boleh bisa masuk maupun memakai token lama. */
    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
