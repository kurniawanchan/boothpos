<?php

namespace App\Http\Requests\Customer;

use App\Exceptions\CustomerCommerceException;

/**
 * Customer commerce (CONTRACT §8) — POST /customer/orders: badan quote +
 * `expected_total` (total yang dilihat pelanggan saat menekan "Pesan") dan
 * header `Idempotency-Key`.
 *
 * Header diperiksa SEBELUM badan: ia syarat protokol, jadi klien yang lupa
 * mengirimnya selalu mendapat kode yang sama (422
 * `idempotency_key_required`) apa pun isi badannya.
 */
class PlaceOrderRequest extends QuoteOrderRequest
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /** Jangkar \A…\z (bukan ^…$): `$` meloloskan baris baru di akhir nilai. */
    private const IDEMPOTENCY_PATTERN = '/\A[A-Za-z0-9_-]{16,64}\z/';

    public function rules(): array
    {
        return parent::rules() + [
            // Uang sebagai desimal polos tanpa tanda: "150000.00", "150000", atau angka JSON.
            'expected_total' => ['required', 'regex:/\A\d{1,12}(\.\d{1,2})?\z/'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->headers->get(self::IDEMPOTENCY_HEADER);

        if (! is_string($key) || preg_match(self::IDEMPOTENCY_PATTERN, $key) !== 1) {
            throw CustomerCommerceException::make('idempotency_key_required', 422);
        }

        return $key;
    }

    protected function prepareForValidation(): void
    {
        $this->idempotencyKey();

        parent::prepareForValidation(); // memotong `items` yang kelewat banyak (lihat QuoteOrderRequest)
    }
}
