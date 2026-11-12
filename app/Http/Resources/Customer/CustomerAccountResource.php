<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer commerce (CONTRACT §5) — proyeksi akun untuk PEMILIKNYA sendiri.
 *
 * Sengaja BUKAN CustomerResource staf: yang itu memuat catatan internal,
 * akun sosial, dan alamat kontak. Di sini hanya enam kolom kontrak; kolom
 * keamanan (`password`, `disabled_at`), `customer_id`, dan `data_mode`
 * tidak pernah ikut. Nama dan telepon berasal dari kontak `customers`.
 *
 * @mixin CustomerAccount
 */
class CustomerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->customer?->name,
            'email' => $this->email,
            'phone' => $this->customer?->phone,
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
