package com.boothpos.customer.feature.auth.data

import com.boothpos.customer.core.data.remote.AccountDto
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import retrofit2.http.Body
import retrofit2.http.POST

// DTO yang memuat kata sandi atau token sengaja BUKAN data class: `toString()` bawaan data class
// akan menuliskan isinya, dan itu tidak boleh sampai ke log atau pesan pengecualian.

@Serializable
class RegisterRequestDto(
    val name: String,
    val email: String,
    val password: String,
    /** Null tidak ikut dikirim (`explicitNulls = false`); server memperlakukannya sebagai kosong. */
    val phone: String? = null,
)

@Serializable
class LoginRequestDto(
    val email: String,
    val password: String,
)

/** Jawaban register (201) dan login (200): `{token, token_type, expires_at, account}`. */
@Serializable
class AuthResponseDto(
    val token: String,
    @SerialName("token_type") val tokenType: String = "Bearer",
    @SerialName("expires_at") val expiresAt: String? = null,
    val account: AccountDto,
)

/**
 * Dua endpoint publik. Interseptor auth tidak menempelkan token ke keduanya, sehingga 401
 * `invalid_credentials` dari login tidak pernah disangka "sesi berakhir".
 */
interface AuthApi {
    @POST("customer/auth/register")
    suspend fun register(@Body body: RegisterRequestDto): AuthResponseDto

    @POST("customer/auth/login")
    suspend fun login(@Body body: LoginRequestDto): AuthResponseDto
}
