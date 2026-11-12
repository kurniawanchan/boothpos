package com.boothpos.customer.core.data.remote

import com.boothpos.customer.core.data.domain.model.Address
import com.boothpos.customer.core.data.domain.model.AddressInput
import com.boothpos.customer.core.network.dto.DataEnvelope
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path

@Serializable
data class AddressDto(
    val id: Long,
    val label: String? = null,
    @SerialName("recipient_name") val recipientName: String,
    val phone: String,
    @SerialName("address_line") val addressLine: String,
    val city: String,
    val province: String,
    @SerialName("postal_code") val postalCode: String? = null,
    val notes: String? = null,
    @SerialName("is_default") val isDefault: Boolean = false,
) {
    // Alamat dan kontak penerima tidak boleh muncul bila objek ini tercetak di log atau laporan crash.
    override fun toString(): String = "AddressDto(id=$id)"
}

/**
 * Kolom opsional (label, kode pos, catatan) SELALU dikirim, sebagai `null` bila dikosongkan.
 * `Json` proyek ini memakai `explicitNulls = false`, yang membuang properti bernilai null; bila
 * kunci itu hilang dari body `PUT`, pelanggan tidak akan pernah bisa mengosongkan kolom yang
 * sebelumnya terisi. Karena itu tipenya [JsonElement] ([JsonNull] tetap ditulis sebagai `null`).
 */
@Serializable
data class AddressRequestDto(
    val label: JsonElement,
    @SerialName("recipient_name") val recipientName: String,
    val phone: String,
    @SerialName("address_line") val addressLine: String,
    val city: String,
    val province: String,
    @SerialName("postal_code") val postalCode: JsonElement,
    val notes: JsonElement,
    @SerialName("is_default") val isDefault: Boolean,
) {
    override fun toString(): String = "AddressRequestDto(isDefault=$isDefault)"
}

fun AddressDto.toDomain() = Address(
    id = id,
    label = label,
    recipientName = recipientName,
    phone = phone,
    addressLine = addressLine,
    city = city,
    province = province,
    postalCode = postalCode,
    notes = notes,
    isDefault = isDefault,
)

fun AddressInput.toRequest() = AddressRequestDto(
    label = optional(label),
    recipientName = recipientName.trim(),
    phone = phone.trim(),
    addressLine = addressLine.trim(),
    city = city.trim(),
    province = province.trim(),
    postalCode = optional(postalCode),
    notes = optional(notes),
    isDefault = isDefault,
)

/** Kolom opsional yang kosong atau hanya spasi dikirim sebagai `null`, bukan string kosong. */
private fun optional(value: String?): JsonElement =
    value?.trim()?.takeIf { it.isNotEmpty() }?.let(::JsonPrimitive) ?: JsonNull

interface AddressApi {
    @GET("customer/addresses")
    suspend fun list(): DataEnvelope<List<AddressDto>>

    @POST("customer/addresses")
    suspend fun create(@Body body: AddressRequestDto): DataEnvelope<AddressDto>

    @PUT("customer/addresses/{id}")
    suspend fun update(@Path("id") id: Long, @Body body: AddressRequestDto): DataEnvelope<AddressDto>

    @DELETE("customer/addresses/{id}")
    suspend fun delete(@Path("id") id: Long): Response<Unit>
}
