package com.boothpos.customer.core.data.remote

import com.boothpos.customer.core.data.domain.model.Account
import com.boothpos.customer.core.data.domain.time.IsoTime
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Bentuk akun di kawat (kontrak §5); dipakai fitur auth (login/register) dan akun (`/customer/me`). */
@Serializable
data class AccountDto(
    val id: Long,
    val name: String,
    val email: String,
    val phone: String? = null,
    @SerialName("email_verified") val emailVerified: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
) {
    // Nama, e-mail, dan telepon tidak boleh muncul bila objek ini tercetak di log atau laporan crash.
    override fun toString(): String = "AccountDto(id=$id)"
}

fun AccountDto.toDomain() = Account(
    id = id,
    name = name,
    email = email,
    phone = phone?.takeIf { it.isNotBlank() },
    emailVerified = emailVerified,
    createdAt = IsoTime.parse(createdAt),
)
