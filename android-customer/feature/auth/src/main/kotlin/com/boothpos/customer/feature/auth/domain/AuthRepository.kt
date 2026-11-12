package com.boothpos.customer.feature.auth.domain

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.network.error.ApiResult

/**
 * Data pendaftaran. Sengaja BUKAN data class: `toString()` bawaan data class akan memuat kata
 * sandi dan data pribadi, dan itu tidak boleh sampai ke log atau laporan crash.
 */
class RegisterInput(
    val name: String,
    val email: String,
    val password: String,
    val phone: String?,
)

/**
 * Daftar dan masuk (kontrak §5). Bila berhasil, sesi langsung dimulai di lapisan data; token tidak
 * pernah dikembalikan ke lapisan presentasi.
 */
interface AuthRepository {
    suspend fun register(input: RegisterInput): ApiResult<Account>

    suspend fun signIn(email: String, password: String): ApiResult<Account>
}
