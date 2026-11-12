package com.boothpos.customer.core.data.domain.address

import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.network.error.ApiResult

/**
 * Alamat kirim milik akun yang sedang masuk (kontrak §7). Dipakai fitur akun (kelola) dan
 * checkout (pilih), karena itu kontraknya ada di sini dan bukan di salah satu modul fitur.
 * Tidak ada salinan lokal: alamat adalah data pribadi dan selalu dibaca dari server.
 */
interface AddressRepository {
    /** Alamat utama lebih dulu. */
    suspend fun list(): ApiResult<List<Address>>

    suspend fun create(input: AddressInput): ApiResult<Address>

    suspend fun update(id: Long, input: AddressInput): ApiResult<Address>

    suspend fun delete(id: Long): ApiResult<Unit>
}
