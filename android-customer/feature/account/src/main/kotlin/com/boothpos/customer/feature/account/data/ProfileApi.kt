package com.boothpos.customer.feature.account.data

import com.boothpos.customer.core.data.remote.AccountDto
import com.boothpos.customer.core.network.dto.DataEnvelope
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PUT

/**
 * `phone` SELALU dikirim, sebagai `null` bila dikosongkan. `Json` proyek ini membuang properti
 * bernilai null (`explicitNulls = false`); bila kuncinya hilang dari body `PUT`, pelanggan tidak
 * dapat menghapus nomor yang pernah diisi. Karena itu tipenya [JsonElement] (`JsonNull` tetap
 * ditulis sebagai `null`). Bukan data class: `toString()` tidak boleh memuat data pribadi.
 */
@Serializable
class UpdateProfileRequestDto(
    val name: String,
    val phone: JsonElement,
)

interface ProfileApi {
    @GET("customer/me")
    suspend fun me(): DataEnvelope<AccountDto>

    @PUT("customer/me")
    suspend fun update(@Body body: UpdateProfileRequestDto): DataEnvelope<AccountDto>
}
