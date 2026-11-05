<?php

namespace App\Http\Requests\Concerns;

use App\Support\SellerGate;
use Illuminate\Validation\Validator;

/**
 * 058 — pengguna ber-seller hanya boleh membuat/mengubah data untuk seller-nya sendiri. `artist_id` yang tidak
 * dikirim pada pembuatan diisi otomatis dengan seller pengguna; nilai seller LAIN ditolak (422), bukan diam-diam
 * ditimpa — supaya klien yang salah ketahuan. Satu implementasi untuk semua FormRequest yang membawa artist_id.
 */
trait ForcesOwnSeller
{
    /** Panggil dari prepareForValidation(); $default=true untuk request pembuatan (isi bila kosong). */
    protected function defaultOwnSeller(bool $default = true): void
    {
        $sellerId = SellerGate::current();

        if ($sellerId !== null && $default && ! $this->filled('artist_id')) {
            $this->merge(['artist_id' => $sellerId]);
        }
    }

    protected function refuseForeignSeller(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $sellerId = SellerGate::current();

            if ($sellerId !== null && $this->filled('artist_id') && (int) $this->input('artist_id') !== $sellerId) {
                $v->errors()->add('artist_id', __('seller.foreign_seller'));
            }
        });
    }
}
