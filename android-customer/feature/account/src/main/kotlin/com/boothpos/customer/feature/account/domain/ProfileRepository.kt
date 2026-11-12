package com.boothpos.customer.feature.account.domain

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.network.error.ApiResult

/**
 * Profil pelanggan yang sedang masuk (kontrak §5: `GET|PUT /customer/me`). Hasil yang berhasil
 * juga memperbarui akun di sesi, sehingga semua layar melihat data yang sama.
 */
interface ProfileRepository {
    suspend fun refresh(): ApiResult<Account>

    /** E-mail tidak dapat diubah di v1. [phone] null/kosong berarti mengosongkan nomor telepon. */
    suspend fun update(name: String, phone: String?): ApiResult<Account>
}
