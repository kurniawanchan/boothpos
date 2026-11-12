<?php

namespace App\Http\Requests\CustomerOrder;

use App\Rules\CustomerVisiblePlainText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer commerce (CONTRACT §9) — dasar semua request fulfilment pesanan
 * pelanggan oleh STAF: hanya pemegang menu `customer_orders`.
 *
 * Lapisannya (aturan 057): middleware `ability:customer_orders,<aksi>` di
 * rute menolak lebih dulu peran yang tidak punya aksinya; pemeriksaan menu di
 * sini tetap berjalan sesudahnya dan juga menjaga rute baca (yang tidak
 * membawa `ability`). Menyembunyikan menu di sidebar hanyalah kosmetik.
 */
abstract class CustomerOrderFulfilmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessMenu('customer_orders') ?? false;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(__('customer_commerce.fulfilment.not_authorized'));
    }

    /**
     * Catatan opsional yang ikut tampil di linimasa PELANGGAN: teks biasa,
     * paling banyak 255 karakter (lebar kolom `note`).
     *
     * @return list<mixed>
     */
    protected function noteRules(): array
    {
        return ['nullable', 'string', 'max:255', new CustomerVisiblePlainText];
    }
}
