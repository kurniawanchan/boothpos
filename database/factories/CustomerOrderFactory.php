<?php

namespace Database\Factories;

use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Support\CustomerOrderNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Customer commerce — pesanan pelanggan untuk test (bawaan: ambil di tempat,
 * menunggu konfirmasi, tanpa item). Item ditambahkan lewat
 * CustomerOrderItemFactory; factory ini TIDAK membuat riwayat status dan
 * tidak menggerakkan stok — keduanya milik service yang diuji.
 *
 * `customer_id` selalu diturunkan dari akunnya supaya keduanya tidak pernah
 * menunjuk kontak yang berbeda (urutan kunci di definition() penting:
 * `customer_account_id` harus sudah terselesaikan lebih dulu).
 */
class CustomerOrderFactory extends Factory
{
    protected $model = CustomerOrder::class;

    public function definition(): array
    {
        return [
            'order_number' => fn () => CustomerOrderNumber::generate(),
            'customer_account_id' => CustomerAccount::factory(),
            'customer_id' => fn (array $attributes) => CustomerAccount::withoutGlobalScopes()
                ->findOrFail($attributes['customer_account_id'])->customer_id,
            'fulfilment_method' => CustomerOrder::FULFILMENT_PICKUP,
            'status' => CustomerOrder::STATUS_AWAITING_CONFIRMATION,
            'contact_name' => fake()->name(),
            'contact_phone' => '0800-'.fake()->numerify('####-####'),
            'contact_email' => fake()->safeEmail(),
            'shipping_address' => null,
            'subtotal' => '50000.00',
            'shipping_cost' => '0.00',
            'total' => '50000.00',
            'notes' => null,
            'idempotency_key' => fn () => Str::random(32),
            'idempotency_hash' => fn () => hash('sha256', Str::random(16)),
        ];
    }

    /** Pesanan kirim-kurir dengan snapshot alamat. */
    public function courier(): static
    {
        return $this->state(fn () => [
            'fulfilment_method' => CustomerOrder::FULFILMENT_COURIER,
            'shipping_address' => [
                'recipient_name' => 'Penerima Uji',
                'phone' => '0800-0000-0000',
                'address_line' => 'Jl. Contoh No. 1',
                'city' => 'Bandung',
                'province' => 'Jawa Barat',
                'postal_code' => '40111',
                'notes' => null,
            ],
        ]);
    }

    /** Hanya menyetel kolom status (untuk menyiapkan keadaan awal test transisi). */
    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
