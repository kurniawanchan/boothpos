<?php

namespace App\Http\Requests\Concerns;

use App\Models\Artist;
use App\Models\Role;
use App\Support\ModeGate;
use Illuminate\Validation\Validator;

/**
 * 058 — SATU definisi validasi "seller milik pengguna" untuk StoreUserRequest dan UpdateUserRequest.
 * Aturan: seller harus ada, aktif, tidak dihapus lunak dan milik mode aktif (Rule::exists melewati scope
 * Eloquent, jadi dicek manual); peran Owner tidak boleh punya seller (research D11).
 */
trait ValidatesUserSeller
{
    /** @return array<int,mixed> */
    protected function sellerRules(?int $currentArtistId = null): array
    {
        return [
            'sometimes', 'nullable', 'integer',
            function (string $attribute, mixed $value, \Closure $fail) use ($currentArtistId) {
                if ($value === null) {
                    return;
                }

                // Pengguna yang SUDAH terikat ke seller ini boleh menyimpan ulang form walau seller-nya kini nonaktif;
                // yang dilarang hanya MEMILIH seller yang tak layak.
                if ($currentArtistId !== null && (int) $value === $currentArtistId) {
                    return;
                }

                $ok = Artist::withoutGlobalScopes()
                    ->whereKey($value)
                    ->whereNull('deleted_at')
                    ->where('data_mode', ModeGate::current())
                    ->where('is_active', true)
                    ->exists();

                if (! $ok) {
                    $fail(__('seller.not_assignable'));
                }
            },
        ];
    }

    /** Peran efektif: yang dikirim, atau (untuk update) peran pengguna saat ini. */
    protected function ownerRoleSelected(?int $fallbackRoleId = null): bool
    {
        $roleId = $this->has('role_id') ? $this->integer('role_id') : $fallbackRoleId;

        return $roleId !== null && (Role::find($roleId)?->isOwner() ?? false);
    }

    protected function refuseSellerForOwner(Validator $validator, ?int $fallbackRoleId = null): void
    {
        $validator->after(function (Validator $v) use ($fallbackRoleId) {
            if ($this->filled('artist_id') && $this->ownerRoleSelected($fallbackRoleId)) {
                $v->errors()->add('artist_id', __('seller.owner_not_assignable'));
            }
        });
    }
}
