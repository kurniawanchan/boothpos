<?php

namespace App\Http\Requests\CustomerOrder;

use App\Rules\CustomerVisiblePlainText;
use App\Support\Couriers;
use Illuminate\Validation\Rule;

/**
 * Customer commerce (CONTRACT §9) — POST /customer-orders/{id}/ship.
 *
 * Kurir WAJIB dari App\Support\Couriers::OPTIONS (satu-satunya daftar kurir),
 * bukan teks bebas seperti ShipmentController pre-order. Nomor resi hanya
 * huruf, angka dan tanda hubung: ia ditampilkan ke pelanggan dan kelak
 * disisipkan ke templat tautan pelacakan, jadi bentuknya dibatasi sejak masuk.
 * Tidak ada field URL: tautan pelacakan tidak pernah diterima dari staf.
 */
class ShipCustomerOrderRequest extends CustomerOrderFulfilmentRequest
{
    /** Jangkar \A…\z (bukan ^…$): `$` meloloskan satu baris baru di akhir nilai. */
    public const TRACKING_NUMBER_PATTERN = '/\A[A-Za-z0-9-]+\z/';

    public function rules(): array
    {
        return [
            'courier_name' => ['required', 'string', Rule::in(Couriers::OPTIONS)],
            'service_name' => ['nullable', 'string', 'max:50', new CustomerVisiblePlainText],
            'tracking_number' => ['nullable', 'string', 'max:50', 'regex:'.self::TRACKING_NUMBER_PATTERN],
            'dispatched_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note' => $this->noteRules(),
        ];
    }
}
