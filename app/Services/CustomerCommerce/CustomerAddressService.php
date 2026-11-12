<?php

namespace App\Services\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\CustomerAccount;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Customer commerce (CONTRACT §7) — alamat tersimpan milik akun pelanggan.
 *
 * Aturan yang dijaga di sini (bukan di controller, bukan constraint DB):
 *  - KEPEMILIKAN: setiap pencarian lewat `$account->addresses()`. Tabel ini
 *    tidak punya `data_mode` maupun scope, jadi mencari dengan id saja (atau
 *    rule `exists:`) akan menembus batas akun DAN batas DEMO/LIVE. Id milik
 *    akun lain dijawab 404 yang sama dengan id yang tidak ada.
 *  - SATU ALAMAT UTAMA: selama akun punya alamat, tepat satu yang utama.
 *    Alamat pertama dipaksa utama; menandai alamat lain sebagai utama
 *    mencabut yang lama dalam transaksi yang sama; menghapus yang utama
 *    menaikkan alamat tersisa yang paling baru dibuat. `is_default: false`
 *    pada alamat utama diabaikan (kalau diterapkan, akun tertinggal tanpa
 *    alamat utama): cara memindahkannya adalah menandai alamat LAIN.
 *  - BATAS JUMLAH: config('customer_commerce.max_addresses').
 *
 * Setiap tulis mengunci baris akun lebih dulu (lockForUpdate), supaya dua
 * permintaan bersamaan dari akun yang sama tidak menghasilkan dua alamat
 * utama atau melewati batas jumlah.
 *
 * Tidak ada ActivityLogger di sini: log aktivitas adalah jejak audit staf,
 * dan alamat adalah data pribadi pelanggan yang tidak boleh tersalin ke sana.
 */
class CustomerAddressService
{
    /**
     * Alamat utama lebih dulu, lalu yang terbaru.
     *
     * @return Collection<int, CustomerAddress>
     */
    public function listFor(CustomerAccount $account): Collection
    {
        return $account->addresses()->get();
    }

    /**
     * @throws CustomerCommerceException 404 `not_found` bila alamat tidak ada ATAU milik akun lain
     */
    public function find(CustomerAccount $account, int $addressId): CustomerAddress
    {
        return $account->addresses()->whereKey($addressId)->first()
            ?? throw CustomerCommerceException::make('not_found', 404);
    }

    /**
     * @param  array<string, mixed>  $data  hasil validasi AddressRequest
     *
     * @throws CustomerCommerceException 409 `address_limit_reached`
     */
    public function create(CustomerAccount $account, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($account, $data) {
            $this->lockAccount($account);

            $existing = $account->addresses()->count();

            if ($existing >= (int) config('customer_commerce.max_addresses')) {
                throw CustomerCommerceException::make('address_limit_reached', 409);
            }

            $makeDefault = $existing === 0 || $this->wantsDefault($data);

            if ($makeDefault && $existing > 0) {
                $this->clearDefault($account);
            }

            return $account->addresses()->create(['is_default' => $makeDefault] + $this->fields($data));
        });
    }

    /**
     * Field opsional yang tidak dikirim dibiarkan; `null` eksplisit
     * mengosongkannya (sama dengan PUT /customer/me).
     *
     * @param  array<string, mixed>  $data  hasil validasi AddressRequest
     */
    public function update(CustomerAccount $account, int $addressId, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($account, $addressId, $data) {
            $this->lockAccount($account);

            $address = $this->find($account, $addressId);
            $address->fill($this->fields($data));

            if ($this->wantsDefault($data) && ! $address->is_default) {
                $this->clearDefault($account);
                $address->is_default = true;
            }

            $address->save();

            return $address;
        });
    }

    public function delete(CustomerAccount $account, int $addressId): void
    {
        DB::transaction(function () use ($account, $addressId) {
            $this->lockAccount($account);

            $address = $this->find($account, $addressId);
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                // "Paling baru dibuat" = id terbesar (created_at bisa kembar dalam satu detik).
                $account->addresses()->reorder()->orderByDesc('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });
    }

    /**
     * Mengantrekan semua tulis alamat akun ini. Lewat model (DataModeScope):
     * akun dari mode data lain tidak akan ditemukan, dan itu memang harus gagal.
     */
    private function lockAccount(CustomerAccount $account): void
    {
        CustomerAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
    }

    private function clearDefault(CustomerAccount $account): void
    {
        $account->addresses()->reorder()->where('is_default', true)->update(['is_default' => false]);
    }

    /** @param  array<string, mixed>  $data */
    private function wantsDefault(array $data): bool
    {
        return filter_var($data['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Hanya kolom alamat; `is_default` diputuskan service, dan pemilik
     * (`customer_account_id`) selalu dari relasi, tidak pernah dari input.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return Arr::only($data, ['label', 'recipient_name', 'phone', 'address_line', 'city', 'province', 'postal_code', 'notes']);
    }
}
